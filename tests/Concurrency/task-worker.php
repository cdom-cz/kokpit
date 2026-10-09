<?php

declare(strict_types=1);

/*
 * Child process of the task number and the board move concurrency tests.
 *
 *   php tests/Concurrency/task-worker.php <startAt> <count> <projectId> <adminId> <allocator> [<board> [<widenMicros> [<mode> [<boardClass> <status> <index> <ids>]]]]
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
 * <mode> `create` (default) is the behaviour above. <mode> `move` moves the cards
 * named in <ids> (comma separated, <count> of them) one by one through MoveTask
 * into <status> at <index>, behind the same start barrier. <boardClass> is the
 * short name `TaskBoard` or `UnlockedTaskBoard` and is bound to TaskBoard in the
 * container, so the mutation run can swap in the board without its advisory
 * lock. The summary of a move run counts `moved` and `failed`.
 *
 * Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Sequences\SequenceAllocator;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Board\TaskBoard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\UnlockedTaskBoard;

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

if ($argc < 6 || $argc > 13) {
    $fail('expected 5 to 12 arguments: startAt count projectId adminId allocator [board [widenMicros [mode [boardClass status index ids]]]]');
}

[, $startAtArg, $countArg, $projectId, $adminId, $allocatorClass] = $argv;
$board = $argv[6] ?? 'locked';
$widenMicros = (int) ($argv[7] ?? 0);
$mode = $argv[8] ?? 'create';

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

if (! in_array($mode, ['create', 'move'], true)) {
    $fail('mode must be create or move');
}

$moveIds = [];
$moveStatus = null;
$moveIndex = 0;

if ($mode === 'move') {
    if ($argc !== 13) {
        $fail('move mode expects boardClass status index ids after the mode');
    }

    $boardClasses = ['TaskBoard' => TaskBoard::class, 'UnlockedTaskBoard' => UnlockedTaskBoard::class];

    if (! isset($boardClasses[$argv[9]])) {
        $fail('the board class must be TaskBoard or UnlockedTaskBoard');
    }

    $moveStatus = ProjectStatus::tryFrom($argv[10]) ?? $fail('the status must be a project status');
    $moveIndex = (int) $argv[11];
    $moveIds = array_values(array_filter(explode(',', $argv[12]), static fn (string $id): bool => $id !== ''));

    if ($moveStatus === ProjectStatus::Done || $moveIndex < 0 || $board !== 'locked' || $widenMicros !== 0) {
        $fail('move mode needs a column status, a non-negative index, the locked board switch and no widening');
    }

    if (count($moveIds) !== $count) {
        $fail('the number of ids must equal the count');
    }

    foreach ($moveIds as $moveId) {
        if (preg_match($uuid, $moveId) !== 1) {
            $fail('every card id must be a uuid');
        }
    }

    $boardClass = $boardClasses[$argv[9]];
    $app->bind(TaskBoard::class, static fn (): TaskBoard => new $boardClass);
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
$action = $mode === 'create' ? $app->make(CreateTask::class) : null;
$mover = $mode === 'move' ? $app->make(MoveTask::class) : null;

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
$moved = 0;
$failed = 0;
$lastError = null;

for ($i = 1; $i <= $count; $i++) {
    try {
        if ($mover !== null) {
            $mover->handle($admin, $moveIds[$i - 1], $moveIndex, $moveStatus, BoardFilters::none());

            $moved++;
        } else {
            $action->handle($admin, $project, ['title' => "Example task {$pid}-{$i}"]);

            $created++;
        }
    } catch (Throwable $e) {
        $failed++;
        $lastError = $e::class.': '.substr($e->getMessage(), 0, 160);
    }
}

echo json_encode([
    'pid' => $pid,
    'created' => $created,
    'moved' => $moved,
    'failed' => $failed,
    'last_error' => $lastError,
], JSON_THROW_ON_ERROR), "\n";

exit($failed === 0 ? 0 : 1);
