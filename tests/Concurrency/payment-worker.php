<?php

declare(strict_types=1);

/*
 * Child process of the payment deletion concurrency test.
 *
 *   php tests/Concurrency/payment-worker.php <startAt> <mode> <adminId> <targetId> [<amount>]
 *
 * Boots the application, signs in the Admin (a worker has no session, so every Partner scope would otherwise be
 * fail-closed), waits for the shared start barrier so the transactions truly overlap, then acts once. <targetId> is the
 * id the mode works on:
 * - `delete`     DeletePayment of the payment <targetId>;
 * - `convert`    ConvertProforma of the proforma <targetId>;
 * - `record`     RecordPayment of <amount> (major units, dated today) on the document <targetId>;
 * - `skip-lock`  test code only, the mutation twin of `delete`. It checks without any lock whether a carried copy of
 *                the proforma payment <targetId> exists (before the barrier), waits for the barrier, then waits until a
 *                converter has committed that copy, and only then sets the deletion flag and deletes the payment
 *                itself. The carried key of the database must refuse it (23503); the document lock of DeletePayment
 *                is what removes that race.
 *
 * Prints one JSON line `{"mode": ..., "result": ..., "pid": ...}` where the result is `deleted`, `converted`,
 * `recorded`, `refused:<key>` for a typed ValidationException (<key> is the key under invoices.errors whose text it
 * carries) or `db:<sqlstate>` for a QueryException; anything else is `error:<class>` and exits 1. Refuses to run
 * unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "payment-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc < 5 || $argc > 6) {
    $fail('expected 4 or 5 arguments: startAt mode adminId targetId [amount]');
}

[, $startAtArg, $mode, $adminId, $targetId] = $argv;
$amount = $argv[5] ?? '';

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

if (! in_array($mode, ['delete', 'convert', 'record', 'skip-lock'], true)) {
    $fail('the mode must be delete, convert, record or skip-lock');
}

if (preg_match($uuid, $adminId) !== 1 || preg_match($uuid, $targetId) !== 1) {
    $fail('the admin id and the target id must be uuids');
}

if ($mode === 'record' && preg_match('/^\d{1,6}$/D', $amount) !== 1) {
    $fail('the record mode needs a whole amount of up to six digits');
}

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

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

// The unlocked check of the mutation twin happens before the barrier, so the converter cannot have committed yet.
$copyBefore = null;
if ($mode === 'skip-lock') {
    $copyBefore = InvoicePayment::query()->where('carried_from_payment_id', $targetId)->exists();
}

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
    switch ($mode) {
        case 'delete':
            $payment = InvoicePayment::query()->findOrFail($targetId);
            $app->make(DeletePayment::class)->handle($admin, $payment);
            $result = 'deleted';
            break;

        case 'convert':
            $proforma = Invoice::query()->findOrFail($targetId);
            $app->make(ConvertProforma::class)->handle($admin, $proforma);
            $result = 'converted';
            break;

        case 'record':
            $invoice = Invoice::query()->findOrFail($targetId);
            $today = CarbonImmutable::now('Europe/Prague')->format('Y-m-d');
            $app->make(RecordPayment::class)->handle($admin, $invoice, ['amount' => $amount, 'paid_on' => $today]);
            $result = 'recorded';
            break;

        default:
            if ($copyBefore !== false) {
                throw new RuntimeException('skip-lock expects no carried copy before the barrier');
            }

            $deadline = microtime(true) + 60.0;
            while (! InvoicePayment::query()->where('carried_from_payment_id', $targetId)->exists()) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('skip-lock waited in vain for the conversion');
                }

                usleep(2000);
            }

            DB::transaction(static function () use ($targetId): void {
                DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$targetId]);
                DB::table('invoice_payments')->where('id', $targetId)->delete();
            });
            $result = 'deleted';
    }
} catch (ValidationException $e) {
    $result = 'refused:'.$errorKey($e);
} catch (QueryException $e) {
    $result = 'db:'.$sqlState($e);
} catch (Throwable $e) {
    $result = 'error:'.$e::class.': '.substr($e->getMessage(), 0, 160);
    $exit = 1;
}

echo json_encode(['mode' => $mode, 'result' => $result, 'pid' => $pid], JSON_THROW_ON_ERROR), "\n";

exit($exit);
