<?php

declare(strict_types=1);

/*
 * Child process of the task number concurrency test.
 *
 *   php tests/Concurrency/task-worker.php <startAt> <count> <projectId> <adminId> <allocator> [<board> [<widenMicros>]]
 *
 * Boots the application, signs in the Admin (a worker has no session, so every
 * Partner scope would otherwise be fail-closed), waits for the shared start
 * barrier so the workers' transactions truly overlap, then calls CreateTask
 * <count> times for the project. Every failure is counted, not fatal. Prints one
 * JSON summary line and exits 1 when any creation failed.
 *
 * <allocator> is bound to SequenceAllocator in the container before CreateTask
 * is resolved, so the mutation run can swap in the allocator without a row lock.
 *
 * Optional, for the proof runs only:
 * - <board> `locked` (default) keeps the board advisory lock; `unlocked` swaps in
 *   a TaskBoard that skips it. The board lock serializes every creation by
 *   itself, so without this switch it would hide a missing counter lock.
 * - <widenMicros> sleeps this long right after the counter row was read, which
 *   widens the race window between reading and advancing the counter.
 *
 * Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Sequences\SequenceAllocator;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Board\TaskBoard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "task-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc < 6 || $argc > 8) {
    $fail('expected 5 to 7 arguments: startAt count projectId adminId allocator [board [widenMicros]]');
}

[, $startAtArg, $countArg, $projectId, $adminId, $allocatorClass] = $argv;
$board = $argv[6] ?? 'locked';
$widenMicros = (int) ($argv[7] ?? 0);

$count = (int) $countArg;
$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

if ($count < 1) {
    $fail('count must be at least 1');
}

if (preg_match($uuid, $projectId) !== 1 || preg_match($uuid, $adminId) !== 1) {
    $fail('the project id and the admin id must be uuids');
}

if (! is_a($allocatorClass, SequenceAllocator::class, true)) {
    $fail('the allocator must be a SequenceAllocator class');
}

if (! in_array($board, ['locked', 'unlocked'], true) || $widenMicros < 0 || $widenMicros > 100000) {
    $fail('board must be locked or unlocked and widenMicros between 0 and 100000');
}

$app->bind(SequenceAllocator::class, static fn (): SequenceAllocator => new $allocatorClass);

if ($board === 'unlocked') {
    $app->bind(TaskBoard::class, static fn (): TaskBoard => new class extends TaskBoard
    {
        #[Override]
        public function lockBoard(): void
        {
            if (DB::transactionLevel() === 0) {
                throw new LogicException('TaskBoard::lockBoard() must run inside a database transaction.');
            }
        }
    });
}

if ($widenMicros > 0) {
    DB::listen(static function (QueryExecuted $query) use ($widenMicros): void {
        if (str_contains($query->sql, 'number_sequences') && str_starts_with(strtoupper(ltrim($query->sql)), 'SELECT')) {
            usleep($widenMicros);
        }
    });
}

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

$project = Project::query()->findOrFail($projectId);
$action = $app->make(CreateTask::class);

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$pid = (int) getmypid();
$created = 0;
$failed = 0;
$lastError = null;

for ($i = 1; $i <= $count; $i++) {
    try {
        $action->handle($admin, $project, ['title' => "Example task {$pid}-{$i}"]);

        $created++;
    } catch (Throwable $e) {
        $failed++;
        $lastError = $e::class.': '.substr($e->getMessage(), 0, 160);
    }
}

echo json_encode([
    'pid' => $pid,
    'created' => $created,
    'failed' => $failed,
    'last_error' => $lastError,
], JSON_THROW_ON_ERROR), "\n";

exit($failed === 0 ? 0 : 1);
