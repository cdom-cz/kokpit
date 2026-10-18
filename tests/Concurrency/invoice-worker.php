<?php

declare(strict_types=1);

/*
 * Child process of the invoice issue concurrency test.
 *
 *   php tests/Concurrency/invoice-worker.php <startAt> <variant> <adminId> <draftIds>
 *
 * Boots the application, signs in the Admin (a worker has no session, so every Partner scope would otherwise be
 * fail-closed), binds the allocator variant, waits for the shared start barrier so the issue transactions truly
 * overlap, then issues the prepared drafts one after the other with IssueDocument. <draftIds> is a comma separated
 * list of draft ids.
 *
 * <variant> is bound to SequenceAllocator in the container before IssueDocument is resolved:
 * - `locked`   the real allocator;
 * - `failing`  the real allocator, but every third allocation throws after the number was taken;
 * - `unlocked` the allocator without the row lock (the mutation run).
 *
 * Prints one JSON line per draft, `{"draft": id, "result": "issued", "number": ..., "pid": ...}` or
 * `{"draft": id, "result": "failed", "planned": bool, "error": ..., "pid": ...}`, and exits 1 when any failure was
 * not the planned one. Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\FailingAfterAllocationSequenceAllocator;
use Tests\Support\UnlockedSequenceAllocator;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "invoice-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 5) {
    $fail('expected 4 arguments: startAt variant adminId draftIds');
}

[, $startAtArg, $variant, $adminId, $draftIdsArg] = $argv;

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
$draftIds = array_values(array_filter(explode(',', $draftIdsArg), static fn (string $id): bool => $id !== ''));

if ($draftIds === []) {
    $fail('at least one draft id is needed');
}

if (preg_match($uuid, $adminId) !== 1) {
    $fail('the admin id must be a uuid');
}

foreach ($draftIds as $draftId) {
    if (preg_match($uuid, $draftId) !== 1) {
        $fail('every draft id must be a uuid');
    }
}

$allocator = match ($variant) {
    'locked' => new SequenceAllocator,
    'failing' => new FailingAfterAllocationSequenceAllocator(range(3, count($draftIds), 3)),
    'unlocked' => new UnlockedSequenceAllocator,
    default => $fail('the variant must be locked, failing or unlocked'),
};

$app->instance(SequenceAllocator::class, $allocator);

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

$action = $app->make(IssueDocument::class);

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$pid = (int) getmypid();
$unplanned = 0;

foreach ($draftIds as $draftId) {
    try {
        $draft = Invoice::query()->findOrFail($draftId);
        $issued = $action->handle($admin, $draft);

        echo json_encode(['draft' => $draftId, 'result' => 'issued', 'number' => $issued->number, 'pid' => $pid], JSON_THROW_ON_ERROR), "\n";
    } catch (Throwable $e) {
        $planned = $e->getMessage() === FailingAfterAllocationSequenceAllocator::MESSAGE;
        $unplanned += $planned ? 0 : 1;

        echo json_encode([
            'draft' => $draftId,
            'result' => 'failed',
            'planned' => $planned,
            'error' => $e::class.': '.substr($e->getMessage(), 0, 160),
            'pid' => $pid,
        ], JSON_THROW_ON_ERROR), "\n";
    }
}

exit($unplanned === 0 ? 0 : 1);
