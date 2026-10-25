<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\InvoiceFixtures;

/*
 * Real parallel PHP processes deleting, converting and paying the same document at the same moment (IN-05, IN-06,
 * FI-03; D-19 as amended on 2026-10-17, ruling R-41-3: the lock order is the document, the payment, the income).
 *
 * Three scenarios run five rounds each, two workers per round behind one barrier: two deletions of one payment,
 * a deletion of the only payment of a proforma against the conversion of that proforma, and a new payment against the
 * deletion of an older one. No round may end in a deadlock or an untyped database error, and every round must leave
 * the payments and incomes of the document consistent. The workers are real processes behind one start barrier, and
 * every scenario is repeated for five rounds so that the overlap is hit more than once.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. Issued rows, payments and payment
 * income are guarded by triggers, so the cleanup disables the user triggers of those tables inside one transaction,
 * deletes the rows of the test's own fictional client, enables them again and asserts that every trigger of those
 * tables is enabled. The supplier and bank settings and the number counters the test touched are put back as they were.
 */

const PAYMENT_DELETION_ROUNDS = 5;
const PAYMENT_DELETION_BARRIER_SECONDS = 5.0;
const PAYMENT_DELETION_TIMEOUT_SECONDS = 180;

/** The guarded tables the cleanup has to open, children first. */
const PAYMENT_DELETION_GUARDED_TABLES = ['invoice_item_sources', 'invoice_items', 'invoice_admin', 'invoice_payments', 'invoices', 'transactions'];

/**
 * Bookkeeping for the rows committed by the running test.
 */
function paymentDeletionScratch(): ArrayObject
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
function paymentDeletionArrange(): array
{
    $scratch = paymentDeletionScratch();

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
 * An issued invoice or proforma of 12 500,00 Kc of the client.
 */
function paymentDeletionIssue(Client $client, User $admin, InvoiceKind $kind): Invoice
{
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '12500.00', 'pcs')], $kind, $admin);

    return app(IssueDocument::class)->handle($admin, $draft);
}

/**
 * Records a payment of the document dated today, as the Admin, and returns its id.
 */
function paymentDeletionPay(Invoice $document, User $admin, string $amount): string
{
    $today = CarbonImmutable::now('Europe/Prague')->format('Y-m-d');

    return app(RecordPayment::class)->handle($admin, $document, ['amount' => $amount, 'paid_on' => $today])->id;
}

/**
 * Removes everything the test committed and puts the settings and the counters back. The guarded tables are opened
 * inside one transaction, so a failure leaves their triggers as they were. Returns the number of rows of its own that
 * are left behind plus the number of triggers that are not enabled (zero when the cleanup is complete).
 */
function paymentDeletionCleanup(): int
{
    $scratch = paymentDeletionScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];

    if ($client !== null) {
        DB::transaction(static function () use ($client): void {
            foreach (PAYMENT_DELETION_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            }

            $invoiceIds = DB::table('invoices')->where('client_id', $client)->pluck('id')->all();
            $itemIds = DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $paymentIds = DB::table('invoice_payments')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $transactionIds = DB::table('transactions')->where('client_id', $client)->pluck('id')->all();

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

            foreach (PAYMENT_DELETION_GUARDED_TABLES as $table) {
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
    $left += $client === null ? 0 : DB::table('transactions')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += paymentDeletionDisabledTriggers();

    $scratch['client'] = $scratch['admin'] = $scratch['settings'] = $scratch['sequences'] = null;
    $scratch['role_created'] = false;

    return $left;
}

/**
 * The number of triggers on the guarded tables, and on the tables next to them, that are not enabled. Zero when every
 * guard is in force.
 */
function paymentDeletionDisabledTriggers(): int
{
    $tables = [...PAYMENT_DELETION_GUARDED_TABLES, 'invoice_emails', 'time_entries'];
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));

    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ({$placeholders}) AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
        $tables,
    )->n;
}

/**
 * Starts one worker per job behind one shared barrier and waits for all of them. A job is a mode, the id it works on
 * and, for `record`, a whole amount.
 *
 * @param  list<array{0: string, 1: string, 2?: string}>  $jobs
 * @return list<string> the result of each job, in job order
 */
function paymentDeletionRun(string $adminId, array $jobs): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + PAYMENT_DELETION_BARRIER_SECONDS, 6, '.', '');

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
    foreach ($jobs as $job) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/payment-worker.php', $barrier, $job[0], $adminId, $job[1], ...(isset($job[2]) ? [$job[2]] : [])],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(PAYMENT_DELETION_TIMEOUT_SECONDS);
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

/**
 * The results with every typed outcome kept and the untyped ones named, for a readable failure message.
 *
 * @param  list<string>  $results
 * @return list<string>
 */
function paymentDeletionUntyped(array $results): array
{
    return array_values(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'db:') || str_starts_with($result, 'error:')));
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = paymentDeletionCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('ends two deletions of one payment in one deletion and one payment_missing refusal, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = paymentDeletionArrange();
    $invoices = [];

    for ($round = 0; $round < PAYMENT_DELETION_ROUNDS; $round++) {
        $invoice = paymentDeletionIssue($client, $admin, InvoiceKind::Invoice);
        $invoices[] = ['invoice' => $invoice->id, 'payment' => paymentDeletionPay($invoice, $admin, '12500')];
    }

    foreach ($invoices as $round => $ids) {
        $results = paymentDeletionRun($admin->id, [['delete', $ids['payment']], ['delete', $ids['payment']]]);
        sort($results);

        expect($results)->toBe(['deleted', 'refused:payment_missing'], "round {$round}")
            ->and(DB::table('invoice_payments')->where('invoice_id', $ids['invoice'])->count())->toBe(0)
            ->and(DB::table('transactions')->where('invoice_id', $ids['invoice'])->count())->toBe(0);
    }
});

it('ends a deletion of a proforma payment racing its conversion in one consistent outcome, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = paymentDeletionArrange();
    $proformas = [];

    for ($round = 0; $round < PAYMENT_DELETION_ROUNDS; $round++) {
        $proforma = paymentDeletionIssue($client, $admin, InvoiceKind::Proforma);
        $proformas[] = ['proforma' => $proforma->id, 'payment' => paymentDeletionPay($proforma, $admin, '12500')];
    }

    foreach ($proformas as $round => $ids) {
        $results = paymentDeletionRun($admin->id, [['delete', $ids['payment']], ['convert', $ids['proforma']]]);

        expect(paymentDeletionUntyped($results))->toBe([], "round {$round} ended in an untyped database error");

        $invoice = DB::table('invoices')->where('proforma_id', $ids['proforma'])->first(['id']);

        if ($results === ['deleted', 'refused:proforma_not_paid']) {
            expect($invoice)->toBeNull("round {$round}")
                ->and(DB::table('invoice_payments')->where('invoice_id', $ids['proforma'])->count())->toBe(0);

            continue;
        }

        expect($results)->toBe(['refused:payment_delete_carried', 'converted'], "round {$round}")
            ->and($invoice)->not->toBeNull()
            ->and(DB::table('invoice_payments')->where('id', $ids['payment'])->count())->toBe(1);

        $copy = DB::table('invoice_payments')->where('invoice_id', $invoice->id)->get(['id', 'carried_from_payment_id']);

        expect($copy)->toHaveCount(1)
            ->and($copy[0]->carried_from_payment_id)->toBe($ids['payment'])
            ->and(DB::table('transactions')->where('invoice_payment_id', $copy[0]->id)->count())->toBe(1);
    }

    // No converted invoice of this test ever lacks its carried payment.
    $withoutCopy = DB::table('invoices as i')
        ->where('i.client_id', $client->id)
        ->whereNotNull('i.proforma_id')
        ->whereNotExists(static fn ($query) => $query->selectRaw('1')->from('invoice_payments as p')->whereColumn('p.invoice_id', 'i.id'))
        ->count();

    expect($withoutCopy)->toBe(0);
});

it('leaves the balance equal to the remaining payments when a payment is recorded while an older one is deleted, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = paymentDeletionArrange();
    $invoices = [];

    for ($round = 0; $round < PAYMENT_DELETION_ROUNDS; $round++) {
        $invoice = paymentDeletionIssue($client, $admin, InvoiceKind::Invoice);
        $invoices[] = [
            'invoice' => $invoice,
            'first' => paymentDeletionPay($invoice, $admin, '5000'),
            'second' => paymentDeletionPay($invoice, $admin, '3000'),
        ];
    }

    foreach ($invoices as $round => $ids) {
        $results = paymentDeletionRun($admin->id, [['record', $ids['invoice']->id, '1000'], ['delete', $ids['first']]]);

        expect($results)->toBe(['recorded', 'deleted'], "round {$round}");

        $payments = DB::table('invoice_payments')->where('invoice_id', $ids['invoice']->id)->pluck('amount_minor', 'id')->all();
        $incomes = DB::table('transactions')->where('invoice_id', $ids['invoice']->id)->pluck('amount_minor', 'invoice_payment_id')->all();
        $paid = app(PartnerContext::class)->runAsSystem(static fn (): int => app(InvoiceBalances::class)->for([$ids['invoice']->id])[$ids['invoice']->id]['paid']);

        // The queries have no ORDER BY, so the physical row order may differ between the two tables; the amounts per
        // payment are what is compared.
        ksort($payments);
        ksort($incomes);

        expect($payments)->toHaveCount(2)
            ->and($payments)->not->toHaveKey($ids['first'])
            ->and($payments)->toHaveKey($ids['second'])
            ->and($incomes)->toBe($payments)
            ->and($paid)->toBe(array_sum($payments));
    }
});
