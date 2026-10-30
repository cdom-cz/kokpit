<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\InvoiceFixtures;

/*
 * Real parallel PHP processes issuing partial credit notes of the same sent invoice at the same moment (IN-10, TI-08;
 * Phase 17.1 D-01, D-02, D-10): the credit note that takes back the last creditable quantity returns the time of the
 * invoice to unbilled, exactly once, and never before every line is credited in full.
 *
 * Three scenarios run five rounds each, two workers per round behind one barrier, on a sent invoice of four finished
 * one-hour entries (one line of 4 h): A) 2 h and 2 h at once, both issued, the time released once; B) 1 h and 2 h at
 * once, both issued, nothing released, then a sequential 1 h that releases it once; C) a sequential 2 h, then 2 h and
 * 2 h at once, one issued and one refused for the quantity, the time released once. Every round arranges its own
 * invoice on a day of its own, so a round never picks up the released entries of an earlier one.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. Issued rows and billed entries are
 * guarded by triggers, so the cleanup disables the user triggers of those tables inside one transaction, deletes the
 * rows of the test's own fictional client, enables them again and asserts that every trigger of those tables is
 * enabled. The supplier and bank settings and the number counters the test touched are put back as they were.
 */

const CREDIT_RACE_ROUNDS = 5;
const CREDIT_RACE_BARRIER_SECONDS = 5.0;
const CREDIT_RACE_TIMEOUT_SECONDS = 180;
const CREDIT_RACE_ENTRIES = 4;

/** The guarded tables the cleanup has to open, children first. */
const CREDIT_RACE_GUARDED_TABLES = ['time_entries', 'invoice_item_sources', 'invoice_items', 'invoice_admin', 'invoices'];

/**
 * Bookkeeping for the rows committed by the running test.
 */
function creditRaceScratch(): ArrayObject
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
 * Commits an Admin, a fictional client with an hourly rate and the supplier and bank settings an issue needs.
 *
 * @return array{admin: User, client: Client}
 */
function creditRaceArrange(): array
{
    $scratch = creditRaceScratch();

    $scratch['settings'] = DB::table('settings')->whereIn('group', ['supplier', 'bank'])->get(['id', 'payload'])->all();
    $scratch['sequences'] = DB::table('number_sequences')->pluck('next_value', 'scope_key')->all();
    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    auth()->setUser($admin);

    InvoiceFixtures::configureSupplier();

    $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(90000, 'CZK')]);
    $scratch['client'] = $client->id;

    return ['admin' => $admin, 'client' => $client];
}

/**
 * A sent invoice of four finished one-hour entries without a project, started on a day of their own (round + 20 days
 * ago), as one line of 4 h.
 *
 * @return array{invoice: string, item: string, entries: list<string>}
 */
function creditRaceInvoice(User $admin, Client $client, int $round): array
{
    $day = CarbonImmutable::now('UTC')->subDays(20 + $round)->setTime(8, 0);
    $entries = [];

    for ($i = 0; $i < CREDIT_RACE_ENTRIES; $i++) {
        $startedAt = $day->addHours($i);

        $entries[] = app(PartnerContext::class)->runAsSystem(static fn (): string => TimeEntry::factory()->create([
            'user_id' => $admin->id,
            'client_id' => $client->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->addHour(),
        ])->id);
    }

    $date = $day->format('Y-m-d');

    $draft = app(CreateDraft::class)->handle($admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => $date,
        'period_to' => $date,
        'project_ids' => [],
        'without_project' => true,
        'grouping' => 'project',
    ]);

    $invoice = InvoiceFixtures::lock(app(IssueDocument::class)->handle($admin, $draft));
    $items = DB::table('invoice_items')->where('invoice_id', $invoice->id)->get(['id', 'quantity']);

    expect($items)->toHaveCount(1)
        ->and((float) $items[0]->quantity)->toBe(4.0)
        ->and(DB::table('time_entries')->whereIn('id', $entries)->where('invoice_id', $invoice->id)->where('billing_state', 'billed')->count())->toBe(CREDIT_RACE_ENTRIES);

    return ['invoice' => $invoice->id, 'item' => (string) $items[0]->id, 'entries' => $entries];
}

/**
 * Removes everything the test committed and puts the settings and the counters back. The guarded tables are opened
 * inside one transaction, so a failure leaves their triggers as they were. Returns the number of rows of its own that
 * are left behind plus the number of triggers that are not enabled (zero when the cleanup is complete).
 */
function creditRaceCleanup(): int
{
    $scratch = creditRaceScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];

    if ($client !== null) {
        DB::transaction(static function () use ($client): void {
            foreach (CREDIT_RACE_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            }

            $invoiceIds = DB::table('invoices')->where('client_id', $client)->pluck('id')->all();
            $itemIds = DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
            $entryIds = DB::table('time_entries')->where('client_id', $client)->pluck('id')->all();

            DB::table('time_entries')->where('client_id', $client)->delete();
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
            DB::table('activity_log')->whereIn('subject_id', [...$invoiceIds, ...$itemIds, ...$entryIds])->delete();
            DB::table('invoices')->where('client_id', $client)->delete();

            foreach (CREDIT_RACE_GUARDED_TABLES as $table) {
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
    $left += $client === null ? 0 : DB::table('time_entries')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += creditRaceDisabledTriggers();

    $scratch['client'] = $scratch['admin'] = $scratch['settings'] = $scratch['sequences'] = null;
    $scratch['role_created'] = false;

    return $left;
}

/**
 * The number of triggers on the guarded tables, and on the tables next to them, that are not enabled. Zero when every
 * guard is in force.
 */
function creditRaceDisabledTriggers(): int
{
    $tables = [...CREDIT_RACE_GUARDED_TABLES, 'invoice_emails', 'invoice_payments', 'transactions'];
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));

    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ({$placeholders}) AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
        $tables,
    )->n;
}

/**
 * Starts one worker per job behind one shared barrier and waits for all of them. A job is the id of the invoice, the id
 * of its line and the quantity to credit. A single job needs no overlap, so it passes with a barrier that has already
 * come.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $jobs
 * @return list<string> the result of each job, in job order
 */
function creditRaceRun(string $adminId, array $jobs): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + (count($jobs) > 1 ? CREDIT_RACE_BARRIER_SECONDS : 0.0), 6, '.', '');

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
            [PHP_BINARY, __DIR__.'/credit-worker.php', $barrier, $adminId, $job[0], $job[1], $job[2]],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(CREDIT_RACE_TIMEOUT_SECONDS);
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
function creditRaceUntyped(array $results): array
{
    return array_values(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'db:') || str_starts_with($result, 'error:')));
}

/**
 * How many history rows record the release of the entry: an update whose new billing state is unbilled.
 */
function creditRaceReleases(string $entryId): int
{
    return DB::table('activity_log')
        ->where('subject_type', 'time_entry')
        ->where('subject_id', $entryId)
        ->where('event', 'updated')
        ->whereRaw("attribute_changes->'attributes'->>'billing_state' = 'unbilled'")
        ->count();
}

/**
 * The state after a round: the number of issued credit notes, none of them a storno, and the time either released
 * exactly once per entry or still billed and linked, with no release recorded.
 *
 * @param  array{invoice: string, item: string, entries: list<string>}  $round
 */
function creditRaceAssert(array $round, int $credits, bool $released, string $label): void
{
    expect(DB::table('invoices')->where('credited_invoice_id', $round['invoice'])->where('kind', 'credit_note')->where('state', 'issued')->count())->toBe($credits, $label)
        ->and(DB::table('invoices')->where('credited_invoice_id', $round['invoice'])->where('cancels_invoice', true)->count())->toBe(0, $label);

    foreach ($round['entries'] as $entry) {
        $row = DB::table('time_entries')->where('id', $entry)->first(['billing_state', 'invoice_id', 'billed_at']);

        if ($released) {
            expect($row->billing_state)->toBe('unbilled', $label)
                ->and($row->invoice_id)->toBeNull($label)
                ->and($row->billed_at)->toBeNull($label)
                ->and(creditRaceReleases($entry))->toBe(1, $label);
        } else {
            expect($row->billing_state)->toBe('billed', $label)
                ->and($row->invoice_id)->toBe($round['invoice'], $label)
                ->and(creditRaceReleases($entry))->toBe(0, $label);
        }
    }
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = creditRaceCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('releases the time once when two partial credit notes take back the whole line at the same moment, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = creditRaceArrange();
    $rounds = [];

    for ($round = 0; $round < CREDIT_RACE_ROUNDS; $round++) {
        $rounds[] = creditRaceInvoice($admin, $client, $round);
    }

    foreach ($rounds as $index => $arranged) {
        $results = creditRaceRun($admin->id, [
            [$arranged['invoice'], $arranged['item'], '2'],
            [$arranged['invoice'], $arranged['item'], '2'],
        ]);

        expect(creditRaceUntyped($results))->toBe([], "round {$index} ended in an untyped error")
            ->and($results)->toBe(['issued', 'issued'], "round {$index}");

        creditRaceAssert($arranged, 2, true, "round {$index}");
    }
});

it('releases nothing while one hour is left and the time once the last hour is credited, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = creditRaceArrange();
    $rounds = [];

    for ($round = 0; $round < CREDIT_RACE_ROUNDS; $round++) {
        $rounds[] = creditRaceInvoice($admin, $client, $round);
    }

    foreach ($rounds as $index => $arranged) {
        $results = creditRaceRun($admin->id, [
            [$arranged['invoice'], $arranged['item'], '1'],
            [$arranged['invoice'], $arranged['item'], '2'],
        ]);

        expect(creditRaceUntyped($results))->toBe([], "round {$index} ended in an untyped error")
            ->and($results)->toBe(['issued', 'issued'], "round {$index}");

        creditRaceAssert($arranged, 2, false, "round {$index} before the last hour");

        $last = creditRaceRun($admin->id, [[$arranged['invoice'], $arranged['item'], '1']]);

        expect($last)->toBe(['issued'], "round {$index}");

        creditRaceAssert($arranged, 3, true, "round {$index} after the last hour");
    }
});

it('issues one of two credit notes of the last two hours, refuses the other and releases the time once, five rounds', function () {
    ['admin' => $admin, 'client' => $client] = creditRaceArrange();
    $rounds = [];

    for ($round = 0; $round < CREDIT_RACE_ROUNDS; $round++) {
        $rounds[] = creditRaceInvoice($admin, $client, $round);
    }

    foreach ($rounds as $index => $arranged) {
        $first = creditRaceRun($admin->id, [[$arranged['invoice'], $arranged['item'], '2']]);

        expect($first)->toBe(['issued'], "round {$index}");

        creditRaceAssert($arranged, 1, false, "round {$index} before the race");

        $results = creditRaceRun($admin->id, [
            [$arranged['invoice'], $arranged['item'], '2'],
            [$arranged['invoice'], $arranged['item'], '2'],
        ]);

        expect(creditRaceUntyped($results))->toBe([], "round {$index} ended in an untyped error");

        sort($results);

        expect($results)->toBe(['issued', 'refused:credit_quantity'], "round {$index}");

        creditRaceAssert($arranged, 2, true, "round {$index} after the race");
    }
});
