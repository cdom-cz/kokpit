<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Spatie\WebhookClient\WebhookConfigRepository;
use Symfony\Component\Process\Process;
use Tests\Support\CardPaymentSetup;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeEvents;

/*
 * Real parallel PHP processes booking card payments at the same moment (PA-05, PA-06, PA-07; D-12, D-15; research
 * Pattern 5, the lock order: the event row, the document, the record, the payment, the income).
 *
 * Four scenarios run five rounds each, two workers per round behind one barrier: two different card payments of one
 * invoice (one match, one duplicate without income), one stored event processed by two workers (one match, one
 * ignored), one event stored by two deliveries (one new row, one existing), and a card payment racing a manual one
 * (either order, the books stay consistent). No round may end in a unique violation, a deadlock or another untyped
 * database error. The mutation twin runs the match without the document lock (the worker mode `skip-lock`) against
 * the real one and must end in two matched payments of one invoice; otherwise a clean run of the locked scenarios
 * would prove nothing.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. Issued rows, payments, payment income,
 * payment links and card payment records are guarded by triggers, so the cleanup disables the user triggers of those
 * tables inside one transaction, deletes the rows of the test's own fictional client, enables them again and asserts
 * that every trigger of those tables is enabled. The supplier, bank and payment settings and the number counters the
 * test touched are put back as they were. Every Stripe-shaped value is assembled at runtime from fragments.
 */

const PAYMENT_MATCH_ROUNDS = 5;
const PAYMENT_MATCH_BARRIER_SECONDS = 5.0;
const PAYMENT_MATCH_TIMEOUT_SECONDS = 180;

/** The guarded tables the cleanup has to open, children first. */
const PAYMENT_MATCH_GUARDED_TABLES = ['card_payments', 'payment_links', 'invoice_item_sources', 'invoice_items', 'invoice_admin', 'invoice_payments', 'invoices', 'transactions'];

/**
 * Bookkeeping for the rows committed by the running test.
 */
function paymentMatchScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'client' => null,
        'admin' => null,
        'role_created' => false,
        'settings' => null,
        'sequences' => null,
        'events' => [],
        'directory' => null,
    ]);
}

/**
 * Commits an Admin, a fictional client with the card payment switched on, and the supplier, bank and payment settings
 * an issue needs.
 *
 * @return array{admin: User, client: Client}
 */
function paymentMatchArrange(): array
{
    $scratch = paymentMatchScratch();

    $scratch['settings'] = DB::table('settings')->whereIn('group', ['supplier', 'bank', 'payments'])->get(['id', 'payload'])->all();
    $scratch['sequences'] = DB::table('number_sequences')->pluck('next_value', 'scope_key')->all();
    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();

    $client = CardPaymentSetup::client();
    $scratch['client'] = $client->id;

    return ['admin' => $admin, 'client' => $client];
}

/**
 * An issued invoice of 12 500,00 Kc with its active payment link, both committed.
 *
 * @return array{invoice: Invoice, link: PaymentLink}
 */
function paymentMatchIssue(Client $client, User $admin): array
{
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '12500.00', 'pcs')], InvoiceKind::Invoice, $admin);
    $invoice = app(IssueDocument::class)->handle($admin, $draft);

    $link = app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->firstOrFail());

    expect($link->state)->toBe(PaymentLinkState::Active)
        ->and($link->amount_minor)->toBe(1_250_000);

    return ['invoice' => $invoice, 'link' => $link];
}

/**
 * The payload of a paid checkout of the link under a new event, session and payment intent.
 *
 * @return array<string, mixed>
 */
function paymentMatchEvent(PaymentLink $link): array
{
    $event = StripeEvents::checkoutFor($link);
    paymentMatchScratch()['events'] = [...paymentMatchScratch()['events'], $event['id']];

    return $event;
}

/**
 * Stores the event as the endpoint does and returns the id of the webhook row.
 *
 * @param  array<string, mixed>  $event
 */
function paymentMatchStore(array $event): string
{
    [$call] = WebhookCall::storeStripeEvent(
        app(WebhookConfigRepository::class)->getConfig('stripe'),
        $event['id'],
        $event['type'],
        $event,
    );

    return (string) $call->getKey();
}

/**
 * Removes everything the test committed and puts the settings and the counters back. The guarded tables are opened
 * inside one transaction, so a failure leaves their triggers as they were. Returns the number of rows of its own that
 * are left behind plus the number of triggers that are not enabled (zero when the cleanup is complete).
 */
function paymentMatchCleanup(): int
{
    $scratch = paymentMatchScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];
    $events = $scratch['events'];

    if ($client !== null) {
        DB::transaction(static function () use ($client, $events): void {
            foreach (PAYMENT_MATCH_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            }

            $invoiceIds = DB::table('invoices')->where('client_id', $client)->pluck('id')->all();
            $itemIds = DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $paymentIds = DB::table('invoice_payments')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $transactionIds = DB::table('transactions')->where('client_id', $client)->pluck('id')->all();
            $linkIds = DB::table('payment_links')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $recordIds = DB::table('card_payments')->whereIn('invoice_id', $invoiceIds)->orWhereIn('stripe_event_id', $events)->pluck('id')->all();

            DB::table('card_payments')->whereIn('id', $recordIds)->delete();
            DB::table('payment_links')->whereIn('id', $linkIds)->delete();
            DB::table('webhook_calls')->whereIn('event_id', $events)->delete();
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
            DB::table('activity_log')->whereIn('subject_id', [...$invoiceIds, ...$itemIds, ...$paymentIds, ...$transactionIds, ...$linkIds, ...$recordIds])->delete();
            DB::table('invoices')->where('client_id', $client)->delete();

            foreach (PAYMENT_MATCH_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
        });

        DB::table('contacts')->where('client_id', $client)->delete();
        DB::table('activity_log')->where('subject_id', $client)->delete();
        DB::table('clients')->where('id', $client)->delete();
    }

    if ($admin !== null) {
        DB::table('notifications')->where('notifiable_id', $admin)->delete();
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

    if (is_string($scratch['directory'])) {
        File::deleteDirectory($scratch['directory']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app()->forgetScopedInstances();

    $left = 0;
    $left += $client === null ? 0 : DB::table('invoices')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('transactions')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += DB::table('payment_links')->whereNotExists(static fn ($query) => $query->selectRaw('1')->from('invoices')->whereColumn('invoices.id', 'payment_links.invoice_id'))->count();
    $left += DB::table('card_payments')->whereIn('stripe_event_id', $events)->count();
    $left += DB::table('webhook_calls')->whereIn('event_id', $events)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('notifications')->where('notifiable_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += paymentMatchDisabledTriggers();

    $scratch['client'] = $scratch['admin'] = $scratch['settings'] = $scratch['sequences'] = $scratch['directory'] = null;
    $scratch['role_created'] = false;
    $scratch['events'] = [];

    return $left;
}

/**
 * The number of triggers on the guarded tables, and on the tables next to them, that are not enabled. Zero when every
 * guard is in force.
 */
function paymentMatchDisabledTriggers(): int
{
    $tables = [...PAYMENT_MATCH_GUARDED_TABLES, 'invoice_emails', 'time_entries'];
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));

    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ({$placeholders}) AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
        $tables,
    )->n;
}

/**
 * Starts one worker per job behind one shared barrier and waits for all of them. A job is a mode, the id (or, for
 * `store`, the payload file) it works on and, for `manual`, a whole amount.
 *
 * @param  list<array{0: string, 1: string, 2?: string}>  $jobs
 * @return list<string> the result of each job, in job order
 */
function paymentMatchRun(string $adminId, array $jobs): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + PAYMENT_MATCH_BARRIER_SECONDS, 6, '.', '');

    $env = [
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
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
            [PHP_BINARY, __DIR__.'/payment-match-worker.php', $barrier, $job[0], $adminId, $job[1], ...(isset($job[2]) ? [$job[2]] : [])],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(PAYMENT_MATCH_TIMEOUT_SECONDS);
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
 * The results that are a database error or an untyped one, for a readable failure message.
 *
 * @param  list<string>  $results
 * @return list<string>
 */
function paymentMatchUntyped(array $results): array
{
    return array_values(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'db:') || str_starts_with($result, 'error:')));
}

/**
 * A scratch directory for the payload files of the `store` workers, removed by the cleanup.
 */
function paymentMatchDirectory(): string
{
    $scratch = paymentMatchScratch();

    if (! is_string($scratch['directory'])) {
        $scratch['directory'] = sys_get_temp_dir().'/payment-match-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($scratch['directory']);
    }

    return $scratch['directory'];
}

/**
 * The card payment records of the document, as a count per type.
 *
 * @return array<string, int>
 */
function paymentMatchRecords(string $invoiceId): array
{
    /** @var array<string, int> $counts */
    $counts = DB::table('card_payments')->where('invoice_id', $invoiceId)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->map(static fn (mixed $n): int => (int) $n)->all();

    return $counts;
}

/**
 * Asserts that the books of the document are consistent: one income per invoice payment, with the amount of the
 * payment, and a paid sum equal to the payments.
 */
function paymentMatchAssertBooks(string $invoiceId, string $label): void
{
    $payments = DB::table('invoice_payments')->where('invoice_id', $invoiceId)->pluck('amount_minor', 'id')->map(static fn (mixed $v): int => (int) $v)->all();
    $incomes = DB::table('transactions')->where('invoice_id', $invoiceId)->pluck('amount_minor', 'invoice_payment_id')->map(static fn (mixed $v): int => (int) $v)->all();
    $paid = app(PartnerContext::class)->runAsSystem(static fn (): int => app(InvoiceBalances::class)->for([$invoiceId])[$invoiceId]['paid']);

    // The queries have no ORDER BY, so the physical row order may differ between the two tables.
    ksort($payments);
    ksort($incomes);

    expect($incomes)->toBe($payments, $label)
        ->and($paid)->toBe(array_sum($payments), $label);
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = paymentMatchCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('ends two different card payments of one invoice in one match and one duplicate without income, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = paymentMatchArrange();
    $rounds = [];

    for ($round = 0; $round < PAYMENT_MATCH_ROUNDS; $round++) {
        ['invoice' => $invoice, 'link' => $link] = paymentMatchIssue($client, $admin);
        $rounds[] = [
            'invoice' => $invoice->id,
            'first' => paymentMatchStore(paymentMatchEvent($link)),
            'second' => paymentMatchStore(paymentMatchEvent($link)),
        ];
    }

    foreach ($rounds as $round => $ids) {
        $results = paymentMatchRun($admin->id, [['match', $ids['first']], ['match', $ids['second']]]);

        expect(paymentMatchUntyped($results))->toBe([], "round {$round} ended in an untyped database error");

        sort($results);

        expect($results)->toBe(['duplicate', 'matched'], "round {$round}")
            ->and(paymentMatchRecords($ids['invoice']))->toBe(['duplicate' => 1, 'matched' => 1])
            ->and(DB::table('invoice_payments')->where('invoice_id', $ids['invoice'])->count())->toBe(1)
            ->and(DB::table('transactions')->where('invoice_id', $ids['invoice'])->count())->toBe(1)
            ->and(DB::table('webhook_calls')->whereIn('id', [$ids['first'], $ids['second']])->whereNotNull('processed_at')->count())->toBe(2);

        $remaining = app(PartnerContext::class)->runAsSystem(static fn (): int => app(InvoiceBalances::class)->for([$ids['invoice']])[$ids['invoice']]['remaining']);

        expect($remaining)->toBe(0, "round {$round}");
        paymentMatchAssertBooks($ids['invoice'], "round {$round}");
    }
});
