<?php

declare(strict_types=1);

/*
 * Child process of the invoice edit versus e-mail queue concurrency test.
 *
 *   php tests/Concurrency/invoice-edit-worker.php <startAt> <role> <variant> <adminId> <invoiceId>
 *
 * Boots the application, signs in the Admin (a worker has no session, so every Partner scope would otherwise be
 * fail-closed), waits for the shared start barrier and then does one thing to the open issued invoice <invoiceId>:
 *
 * - role `edit`   UpdateIssuedDocument changes the quantity of the first line. The transaction around it is kept open
 *                 for a fixed time after the Action returned, like an edit of a big document that renders slowly, so the
 *                 invoice row stays locked while the other process arrives;
 * - role `queue`  QueueInvoiceEmail queues the document e-mail, starting KOKPIT_QUEUE_DELAY seconds after the barrier
 *                 (seconds as a decimal, default 0) so that it lands inside the edit transaction. The job itself is not
 *                 dispatched (Bus is faked): only the queued row is the subject.
 *
 * <variant> is bound to QueueInvoiceEmail in the container, for the role `queue` only:
 * - `locked`    the real Action;
 * - `unlocked`  Tests\Support\UnlockedQueueInvoiceEmail, which reads the document without the invoice row lock (the
 *               mutation run).
 *
 * Prints one JSON line, `{"role": ..., "result": "ok"|"refused"|"failed", "error": ..., "at": ..., "seen_total": ...,
 * "pid": ...}`, where `at` is `microtime(true)` taken right after the transaction returned, that is after its commit,
 * and `seen_total` (role `queue`) is the total in minor units of the document as the Action first read it after the
 * barrier, the figure it decided on. Exits 1 on a failure that is not a refusal of the edit. Refuses to run unless the
 * database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\UnlockedQueueInvoiceEmail;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** How long the edit keeps its transaction open after the Action returned, in seconds. */
const EDIT_HOLD_SECONDS = 0.6;

$fail = static function (string $message): never {
    fwrite(STDERR, "invoice-edit-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 6) {
    $fail('expected 5 arguments: startAt role variant adminId invoiceId');
}

[, $startAtArg, $role, $variant, $adminId, $invoiceId] = $argv;

$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

if (! in_array($role, ['edit', 'queue'], true)) {
    $fail('the role must be edit or queue');
}

if (! in_array($variant, ['locked', 'unlocked'], true)) {
    $fail('the variant must be locked or unlocked');
}

if (preg_match($uuid, $adminId) !== 1 || preg_match($uuid, $invoiceId) !== 1) {
    $fail('the admin id and the invoice id must be uuids');
}

if ($role === 'queue') {
    // The twin differs from the real Action in the read of the document only.
    if ($variant === 'unlocked') {
        $app->singleton(QueueInvoiceEmail::class, static fn (): QueueInvoiceEmail => new UnlockedQueueInvoiceEmail);
    }

    // The queue of the test environment is synchronous; the e-mail job must not run here.
    Bus::fake();
}

$admin = User::query()->findOrFail($adminId);
auth()->setUser($admin);

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);
$delayEnv = getenv('KOKPIT_QUEUE_DELAY');
$delay = $role === 'queue' && $delayEnv !== false && $delayEnv !== '' ? (float) $delayEnv : 0.0;

// Open the connection and load the document before the barrier so nothing is paid for after it.
$invoice = Invoice::query()->findOrFail($invoiceId);
$lines = InvoiceItem::query()->where('invoice_id', $invoiceId)->orderBy('position')->get();

while (microtime(true) < $barrierAt + $delay) {
    usleep(100);
}

$pid = (int) getmypid();

// The document as the queue read it: the first load of the invoice after the barrier.
$seenTotal = null;
Invoice::retrieved(static function (Invoice $read) use (&$seenTotal): void {
    $seenTotal ??= $read->total_minor;
});

try {
    if ($role === 'edit') {
        $items = [];

        foreach ($lines as $i => $line) {
            $items[] = [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => $i === 0 ? '2' : $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unitPrice()->toMajor(),
            ];
        }

        DB::transaction(static function () use ($app, $admin, $invoice, $items): void {
            $app->make(UpdateIssuedDocument::class)->handle($admin, $invoice, ['items' => $items]);

            DB::select('select pg_sleep(?)', [EDIT_HOLD_SECONDS]);
        });
    } else {
        $app->make(QueueInvoiceEmail::class)->handle($admin, $invoice, [
            'recipient' => 'jane'.'@example.com',
            'subject' => 'Example subject',
            'body' => 'Example body',
            'attach_work_report' => false,
        ]);
    }

    echo json_encode(['role' => $role, 'result' => 'ok', 'at' => microtime(true), 'seen_total' => $seenTotal, 'pid' => $pid], JSON_THROW_ON_ERROR), "\n";
    exit(0);
} catch (ValidationException $e) {
    $refused = $role === 'edit' && isset($e->errors()['document']);

    echo json_encode([
        'role' => $role,
        'result' => $refused ? 'refused' : 'failed',
        'error' => json_encode($e->errors(), JSON_UNESCAPED_UNICODE),
        'at' => microtime(true),
        'pid' => $pid,
    ], JSON_THROW_ON_ERROR), "\n";
    exit($refused ? 0 : 1);
} catch (Throwable $e) {
    echo json_encode([
        'role' => $role,
        'result' => 'failed',
        'error' => $e::class.': '.substr($e->getMessage(), 0, 160),
        'at' => microtime(true),
        'pid' => $pid,
    ], JSON_THROW_ON_ERROR), "\n";
    exit(1);
}
