<?php

declare(strict_types=1);

/*
 * Child process of the credit note concurrency test.
 *
 *   php tests/Concurrency/credit-worker.php <startAt> <adminId> <invoiceId> <itemId> <quantity>
 *
 * Boots the application, signs in the Admin (a worker has no session, so every Partner scope would otherwise be
 * fail-closed), waits for the shared start barrier so the transactions truly overlap, then issues one partial credit
 * note of <quantity> (a positive decimal with at most four decimals) of the line <itemId> of the invoice <invoiceId>.
 *
 * Prints one JSON line `{"result": ..., "pid": ...}` where the result is `issued`, `refused:<key>` for a typed
 * ValidationException (<key> is the key under invoices.errors whose text it carries) or `db:<sqlstate>` for a
 * QueryException; anything else is `error:<class>: <message>` and exits 1. Refuses to run unless the database name ends
 * in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "credit-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 6) {
    $fail('expected 5 arguments: startAt adminId invoiceId itemId quantity');
}

[, $startAtArg, $adminId, $invoiceId, $itemId, $quantity] = $argv;

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

if (preg_match($uuid, $adminId) !== 1 || preg_match($uuid, $invoiceId) !== 1 || preg_match($uuid, $itemId) !== 1) {
    $fail('the admin id, the invoice id and the item id must be uuids');
}

if (preg_match('/^\d{1,6}(\.\d{1,4})?$/D', $quantity) !== 1 || (float) $quantity <= 0.0) {
    $fail('the quantity must be a positive decimal with at most four decimals');
}

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

$invoice = Invoice::query()->findOrFail($invoiceId);

/**
 * The key under invoices.errors whose text the validation error carries, or its first message.
 */
$errorKey = static function (ValidationException $e): string {
    $message = (string) (collect($e->errors())->flatten()->first() ?? '');
    $texts = trans('invoices.errors');

    foreach (is_array($texts) ? $texts : [] as $key => $text) {
        if (! is_string($text)) {
            continue;
        }

        $parts = preg_split('/:[a-z_]+/', $text) ?: [];
        $pattern = '/^'.implode('.+', array_map(static fn (string $part): string => preg_quote($part, '/'), $parts)).'$/Du';

        if (preg_match($pattern, $message) === 1) {
            return (string) $key;
        }
    }

    return $message;
};

$sqlState = static function (QueryException $e): string {
    $previous = $e->getPrevious();

    if ($previous instanceof PDOException && is_array($previous->errorInfo) && isset($previous->errorInfo[0])) {
        return (string) $previous->errorInfo[0];
    }

    return (string) $e->getCode();
};

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$pid = (int) getmypid();
$exit = 0;

try {
    $app->make(IssueCreditNote::class)->partial($admin, $invoice, [$itemId => $quantity]);
    $result = 'issued';
} catch (ValidationException $e) {
    $result = 'refused:'.$errorKey($e);
} catch (QueryException $e) {
    $result = 'db:'.$sqlState($e);
} catch (Throwable $e) {
    $result = 'error:'.$e::class.': '.substr($e->getMessage(), 0, 160);
    $exit = 1;
}

echo json_encode(['result' => $result, 'pid' => $pid], JSON_THROW_ON_ERROR), "\n";

exit($exit);
