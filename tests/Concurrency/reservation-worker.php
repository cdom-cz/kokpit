<?php

declare(strict_types=1);

/*
 * Child process of the time reservation concurrency test.
 *
 *   php tests/Concurrency/reservation-worker.php <startAt> <variant> <adminId> <clientId> <periodFrom> <periodTo>
 *
 * Boots the application, signs in the Admin (a worker has no session, so every Partner scope would otherwise be
 * fail-closed), waits for the shared start barrier so the draft transactions truly
 * overlap, then creates one draft for the client from the unbilled time without a project over the period (Prague
 * days, `Y-m-d`).
 *
 * <variant> `locked` is the real EntryReservation claim, which locks the candidates in id order.
 *
 * Prints one JSON line, `{"pid": ..., "result": "created", "draft": id, "line_seconds": n}` or
 * `{"pid": ..., "result": "failed", "error": ...}`, and exits 1 when the draft was not created. Refuses to run
 * unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "reservation-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 7) {
    $fail('expected 6 arguments: startAt variant adminId clientId periodFrom periodTo');
}

[, $startAtArg, $variant, $adminId, $clientId, $periodFrom, $periodTo] = $argv;

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
$day = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D';

if (preg_match($uuid, $adminId) !== 1 || preg_match($uuid, $clientId) !== 1) {
    $fail('the admin id and the client id must be uuids');
}

if (preg_match($day, $periodFrom) !== 1 || preg_match($day, $periodTo) !== 1) {
    $fail('the period must be two Y-m-d days');
}

if ($variant !== 'locked') {
    $fail('the variant must be locked');
}

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

$action = $app->make(CreateDraft::class);

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$pid = (int) getmypid();

try {
    $draft = $action->handle($admin, [
        'client_id' => $clientId,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
        'project_ids' => [],
        'without_project' => true,
        'grouping' => 'project',
    ]);

    $lineSeconds = (int) DB::table('invoice_item_sources AS s')
        ->join('invoice_items AS i', 'i.id', '=', 's.invoice_item_id')
        ->where('i.invoice_id', $draft->id)
        ->sum('s.source_seconds');

    echo json_encode(['pid' => $pid, 'result' => 'created', 'draft' => $draft->id, 'line_seconds' => $lineSeconds], JSON_THROW_ON_ERROR), "\n";

    exit(0);
} catch (Throwable $e) {
    echo json_encode(['pid' => $pid, 'result' => 'failed', 'error' => $e::class.': '.substr($e->getMessage(), 0, 160)], JSON_THROW_ON_ERROR), "\n";

    exit(1);
}
