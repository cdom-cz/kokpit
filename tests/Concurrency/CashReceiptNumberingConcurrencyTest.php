<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\InvoiceFixtures;

/*
 * Real parallel PHP processes recording a cash payment at the same moment (Phase 15, P15-06, D-09): every cash payment
 * issues its receipt in its own transaction, and the number comes from the locked counter of the `cash_receipt` series,
 * so two payments recorded together must end with two different, consecutive numbers and a counter that moved by exactly
 * the number of receipts.
 *
 * Five rounds run, two workers per round behind one barrier, each paying a different issued invoice of one fictional
 * client in cash (mode `record-cash` of payment-worker.php). Every round must end with two receipts of distinct,
 * consecutive numbers and no worker error, and over all rounds the numbers must be one unbroken run.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. Issued rows, payments, income and
 * receipts are guarded by triggers, so the cleanup disables the user triggers of those tables inside one transaction,
 * deletes the rows of the test's own fictional client (receipts first, because a payment with a receipt cannot be
 * deleted), enables the triggers again and asserts that every trigger of those tables is enabled. The supplier and bank
 * settings and the number counters the test touched are put back as they were.
 */

const CASH_RECEIPT_NUMBERING_ROUNDS = 5;
const CASH_RECEIPT_NUMBERING_BARRIER_SECONDS = 5.0;
const CASH_RECEIPT_NUMBERING_TIMEOUT_SECONDS = 180;

/** The guarded tables the cleanup has to open, children first. */
const CASH_RECEIPT_NUMBERING_GUARDED_TABLES = ['cash_receipts', 'invoice_item_sources', 'invoice_items', 'invoice_admin', 'invoice_payments', 'invoices', 'transactions'];

/**
 * Bookkeeping for the rows committed by the running test.
 */
function cashReceiptNumberingScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'client' => null,
        'admin' => null,
        'role_created' => false,
        'settings' => null,
        'sequences' => null,
    ]);
}

/**
 * Commits an Admin, a fictional client and the supplier and bank settings an issue needs.
 *
 * @return array{admin: User, client: Client}
 */
function cashReceiptNumberingArrange(): array
{
    $scratch = cashReceiptNumberingScratch();

    $scratch['settings'] = DB::table('settings')->whereIn('group', ['supplier', 'bank'])->get(['id', 'payload'])->all();
    $scratch['sequences'] = DB::table('number_sequences')->pluck('next_value', 'scope_key')->all();
    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    InvoiceFixtures::configureSupplier();

    $client = InvoiceFixtures::client();
    $scratch['client'] = $client->id;

    return ['admin' => $admin, 'client' => $client];
}

/**
 * An issued invoice of 12 500,00 Kc of the client.
 */
function cashReceiptNumberingIssue(Client $client, User $admin): Invoice
{
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '12500.00', 'pcs')], InvoiceKind::Invoice, $admin);

    return app(IssueDocument::class)->handle($admin, $draft);
}

/**
 * The scope key of the receipt counter of the current year, as the allocator builds it.
 */
function cashReceiptNumberingScopeKey(): string
{
    return 'cash_receipt:'.CarbonImmutable::now('Europe/Prague')->format('Y');
}

/**
 * The running number at the end of a receipt number (the pattern ends in the four-digit counter).
 */
function cashReceiptNumberingSerial(string $number): int
{
    preg_match('/(\d{4})$/D', $number, $match);

    return (int) ($match[1] ?? -1);
}

/**
 * Removes everything the test committed and puts the settings and the counters back. The guarded tables are opened
 * inside one transaction, so a failure leaves their triggers as they were. Returns the number of rows of its own that
 * are left behind plus the number of triggers that are not enabled (zero when the cleanup is complete).
 */
function cashReceiptNumberingCleanup(): int
{
    $scratch = cashReceiptNumberingScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];

    if ($client !== null) {
        DB::transaction(static function () use ($client): void {
            foreach (CASH_RECEIPT_NUMBERING_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            }

            $invoiceIds = DB::table('invoices')->where('client_id', $client)->pluck('id')->all();
            $itemIds = DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $paymentIds = DB::table('invoice_payments')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $transactionIds = DB::table('transactions')->where('client_id', $client)->pluck('id')->all();
            $receiptIds = DB::table('cash_receipts')->where('client_id', $client)->pluck('id')->all();

            // A payment with a receipt cannot be deleted (the payment link check), so the receipts go first.
            DB::table('activity_log')->whereIn('subject_id', $receiptIds)->delete();
            DB::table('cash_receipts')->where('client_id', $client)->delete();
            DB::table('transactions')->where('client_id', $client)->delete();
            DB::table('invoice_payments')->whereIn('id', $paymentIds)->whereNotNull('carried_from_payment_id')->delete();
            DB::table('invoice_payments')->whereIn('id', $paymentIds)->delete();
            DB::table('invoice_item_sources')->whereIn('invoice_item_id', $itemIds)->delete();
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->update(['credited_item_id' => null]);
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoice_admin')->whereIn('invoice_id', $invoiceIds)->delete();

            // The PDF of each issued document: the document row, its media row and the stored object.
            $documentIds = DB::table('documents')->where('client_id', $client)->pluck('id')->all();

            foreach (DB::table('media')->where('model_type', 'document')->whereIn('model_id', $documentIds)->get(['id', 'disk']) as $media) {
                Storage::disk($media->disk)->deleteDirectory((string) $media->id);
            }

            DB::table('media')->where('model_type', 'document')->whereIn('model_id', $documentIds)->delete();
            DB::table('activity_log')->whereIn('subject_id', $documentIds)->delete();
            DB::table('documents')->whereIn('id', $documentIds)->delete();
            DB::table('activity_log')->whereIn('subject_id', [...$invoiceIds, ...$itemIds, ...$paymentIds, ...$transactionIds])->delete();
            DB::table('invoices')->where('client_id', $client)->delete();

            foreach (CASH_RECEIPT_NUMBERING_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
        });

        DB::table('contacts')->where('client_id', $client)->delete();
        DB::table('activity_log')->where('subject_id', $client)->delete();
        DB::table('clients')->where('id', $client)->delete();
    }

    if ($admin !== null) {
        DB::table('model_has_roles')->where('model_id', $admin)->delete();
        DB::table('activity_log')->where('causer_id', $admin)->orWhere('subject_id', $admin)->delete();
        DB::table('users')->where('id', $admin)->delete();
    }

    if ($scratch['role_created']) {
        DB::table('roles')->where('name', RoleName::Admin->value)->where('guard_name', 'web')->delete();
    }

    if (is_array($scratch['sequences'])) {
        foreach (DB::table('number_sequences')->pluck('next_value', 'scope_key')->all() as $key => $value) {
            if (! array_key_exists($key, $scratch['sequences'])) {
                DB::table('number_sequences')->where('scope_key', $key)->delete();
            } elseif ((int) $scratch['sequences'][$key] !== (int) $value) {
                DB::table('number_sequences')->where('scope_key', $key)->update(['next_value' => $scratch['sequences'][$key]]);
            }
        }
    }

    if ($scratch['settings'] !== null) {
        foreach ($scratch['settings'] as $row) {
            DB::table('settings')->where('id', $row->id)->update(['payload' => $row->payload]);
        }
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app()->forgetScopedInstances();

    $left = 0;
    $left += $client === null ? 0 : DB::table('invoices')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('cash_receipts')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('transactions')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += cashReceiptNumberingDisabledTriggers();

    $scratch['client'] = $scratch['admin'] = $scratch['settings'] = $scratch['sequences'] = null;
    $scratch['role_created'] = false;

    return $left;
}

/**
 * The number of triggers on the guarded tables, and on the tables next to them, that are not enabled. Zero when every
 * guard is in force.
 */
function cashReceiptNumberingDisabledTriggers(): int
{
    $tables = [...CASH_RECEIPT_NUMBERING_GUARDED_TABLES, 'invoice_emails', 'time_entries'];
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));

    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ({$placeholders}) AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
        $tables,
    )->n;
}

/**
 * Starts one `record-cash` worker per invoice behind one shared barrier and waits for all of them.
 *
 * @param  list<string>  $invoiceIds  the invoices to pay, one worker each
 * @return list<string> the result of each worker, in order
 */
function cashReceiptNumberingRun(string $adminId, array $invoiceIds, string $amount): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + CASH_RECEIPT_NUMBERING_BARRIER_SECONDS, 6, '.', '');

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) config("database.connections.{$connection}.host"),
        'DB_PORT' => (string) config("database.connections.{$connection}.port"),
        'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
        'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
        'DB_URL' => '',
        'KOKPIT_BARRIER_AT' => $barrier,
    ];

    $processes = [];
    foreach ($invoiceIds as $invoiceId) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/payment-worker.php', $barrier, 'record-cash', $adminId, $invoiceId, $amount],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(CASH_RECEIPT_NUMBERING_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $i => $process) {
        $process->wait();

        $result = null;
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['result']) && is_string($decoded['result'])) {
                $result = $decoded['result'];
            }
        }

        expect($result)->not->toBeNull("worker {$i} printed no result: {$process->getErrorOutput()}");
        $results[] = (string) $result;
    }

    return $results;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = cashReceiptNumberingCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('gives two cash payments recorded at the same moment two distinct consecutive receipt numbers, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = cashReceiptNumberingArrange();

    $scopeKey = cashReceiptNumberingScopeKey();
    $start = (int) (DB::table('number_sequences')->where('scope_key', $scopeKey)->value('next_value') ?? 1);
    $rounds = [];

    for ($round = 0; $round < CASH_RECEIPT_NUMBERING_ROUNDS; $round++) {
        $rounds[] = [cashReceiptNumberingIssue($client, $admin)->id, cashReceiptNumberingIssue($client, $admin)->id];
    }

    expect(DB::table('cash_receipts')->where('client_id', $client->id)->count())->toBe(0);

    foreach ($rounds as $round => $invoiceIds) {
        $results = cashReceiptNumberingRun($admin->id, $invoiceIds, '1500');

        expect($results)->toBe(['recorded', 'recorded'], "round {$round}");

        $numbers = DB::table('cash_receipts')->whereIn('invoice_id', $invoiceIds)->pluck('number')->all();
        $serials = array_map('cashReceiptNumberingSerial', $numbers);
        sort($serials);

        expect($numbers)->toHaveCount(2, "round {$round}")
            ->and(array_unique($numbers))->toHaveCount(2, "round {$round}")
            ->and($serials[1] - $serials[0])->toBe(1, "round {$round} numbers are not consecutive")
            ->and(DB::table('invoice_payments')->whereIn('invoice_id', $invoiceIds)->where('method', 'cash')->count())->toBe(2, "round {$round}")
            ->and(DB::table('cash_receipts')->whereIn('invoice_id', $invoiceIds)->whereNotNull('invoice_payment_id')->count())->toBe(2, "round {$round}");
    }

    // Over all rounds the numbers are one unbroken run, and the counter moved by exactly the number of receipts.
    $all = array_map('cashReceiptNumberingSerial', DB::table('cash_receipts')->where('client_id', $client->id)->pluck('number')->all());
    sort($all);

    expect($all)->toBe(range($start, $start + (CASH_RECEIPT_NUMBERING_ROUNDS * 2) - 1))
        ->and((int) DB::table('number_sequences')->where('scope_key', $scopeKey)->value('next_value'))->toBe($start + (CASH_RECEIPT_NUMBERING_ROUNDS * 2));
});
