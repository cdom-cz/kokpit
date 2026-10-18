<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\InvoiceFixtures;

/*
 * Real parallel PHP processes creating drafts from the same unbilled time at the same moment (IN-02, D-04).
 *
 * Four workers create a draft from the same 24 committed entries of one fictional client behind one barrier. With the
 * locked claim every entry ends in exactly one draft and the seconds on the lines of each draft equal the seconds of
 * the entries it holds. The same harness run with the claim that reads without a lock (the worker variant `unlocked`
 * binds Tests\Support\UnlockedEntryReservation) must show a defect, otherwise a clean run proves nothing.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. Drafts are not guarded, so the cleanup
 * releases the entries, deletes the drafts and then the entries, and reports what it could not remove.
 */

const INVOICE_RESERVATION_WORKERS = 4;
const INVOICE_RESERVATION_ENTRIES = 24;
const INVOICE_RESERVATION_BARRIER_SECONDS = 5.0;
const INVOICE_RESERVATION_TIMEOUT_SECONDS = 120;
const INVOICE_RESERVATION_MUTATION_ROUNDS = 3;

/**
 * Bookkeeping for the rows committed by the running test.
 */
function invoiceReservationScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject(['client' => null, 'admin' => null, 'role_created' => false]);
}

/**
 * Commits an Admin, a fictional client with an hourly rate and 24 finished, billable, unbilled entries of it without
 * a project, started two days ago, each with its own number of seconds.
 *
 * @return array{admin: string, client: string, from: string, to: string, seconds: int}
 */
function invoiceReservationArrange(): array
{
    $scratch = invoiceReservationScratch();

    $scratch['role_created'] = $scratch['role_created'] || ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    $client = InvoiceFixtures::client(['hourly_rate' => Money::ofMinor(90000, 'CZK')]);
    $scratch['client'] = $client->id;

    $start = CarbonImmutable::now()->subDays(2)->startOfMinute();
    $seconds = 0;

    for ($i = 0; $i < INVOICE_RESERVATION_ENTRIES; $i++) {
        $duration = 600 + 37 * $i;
        $seconds += $duration;
        $startedAt = $start->addMinutes(30 * $i);

        app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
            'user_id' => $admin->id,
            'client_id' => $client->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->addSeconds($duration),
        ]));
    }

    return [
        'admin' => $admin->id,
        'client' => $client->id,
        'from' => CarbonImmutable::now('Europe/Prague')->subDays(3)->format('Y-m-d'),
        'to' => CarbonImmutable::now('Europe/Prague')->format('Y-m-d'),
        'seconds' => $seconds,
    ];
}

/**
 * Removes everything the test committed, in foreign-key order, and returns the number of rows of its own that are
 * left behind (zero when the cleanup is complete).
 */
function invoiceReservationCleanup(): int
{
    $scratch = invoiceReservationScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];

    if ($client !== null) {
        $invoiceIds = DB::table('invoices')->where('client_id', $client)->pluck('id')->all();
        $itemIds = DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();
        $entryIds = DB::table('time_entries')->where('client_id', $client)->pluck('id')->all();

        DB::transaction(static function () use ($client, $invoiceIds, $itemIds, $entryIds): void {
            // Reservations are released first: a draft cannot be deleted while an entry points at it.
            DB::table('time_entries')->where('client_id', $client)->whereNotNull('invoice_id')->update(['invoice_id' => null]);

            DB::table('invoice_item_sources')->whereIn('invoice_item_id', $itemIds)->delete();
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoice_admin')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('activity_log')->whereIn('subject_id', [...$invoiceIds, ...$itemIds, ...$entryIds])->delete();
            DB::table('invoices')->where('client_id', $client)->delete();
            DB::table('time_entries')->where('client_id', $client)->delete();
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

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $left = 0;
    $left += $client === null ? 0 : DB::table('time_entries')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('invoices')->where('client_id', $client)->count();
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();

    $scratch['client'] = $scratch['admin'] = null;
    $scratch['role_created'] = false;

    return $left;
}

/**
 * Starts the workers behind one shared barrier and waits for all of them.
 *
 * @param  array{admin: string, client: string, from: string, to: string, seconds: int}  $arranged
 * @param  'locked'|'unlocked'  $variant
 * @return list<array{exit: int|null, summary: array{pid: int, result: string, draft?: string, line_seconds?: int, error?: string}|null, stderr: string}>
 */
function invoiceReservationRun(array $arranged, string $variant): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + INVOICE_RESERVATION_BARRIER_SECONDS, 6, '.', '');

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
    for ($i = 0; $i < INVOICE_RESERVATION_WORKERS; $i++) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/reservation-worker.php', $barrier, $variant, $arranged['admin'], $arranged['client'], $arranged['from'], $arranged['to']],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(INVOICE_RESERVATION_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();

        $decoded = json_decode(trim($process->getOutput()), true);

        $results[] = [
            'exit' => $process->getExitCode(),
            'summary' => is_array($decoded) && isset($decoded['pid'], $decoded['result']) ? $decoded : null,
            'stderr' => $process->getErrorOutput(),
        ];
    }

    return $results;
}

/**
 * What the time looks like after a run, as readable defects (empty when every entry is in exactly one draft and every
 * draft bills the seconds of the entries it holds): a worker that crashed or produced no summary, an entry that no
 * draft holds, a draft whose lines carry other seconds than its entries, and seconds on the lines of all drafts that
 * are not the seconds of the 24 entries.
 *
 * @param  list<array{exit: int|null, summary: array{pid: int, result: string, draft?: string, line_seconds?: int, error?: string}|null, stderr: string}>  $results
 * @param  array{admin: string, client: string, from: string, to: string, seconds: int}  $arranged
 * @return list<string>
 */
function invoiceReservationDefects(array $results, array $arranged): array
{
    $defects = [];

    foreach ($results as $i => $result) {
        if ($result['summary'] === null) {
            $defects[] = "worker {$i} produced no summary: {$result['stderr']}";

            continue;
        }

        if ($result['summary']['result'] !== 'created' || $result['exit'] !== 0) {
            $defects[] = "worker {$i} crashed: ".($result['summary']['error'] ?? $result['stderr']);
        }
    }

    $free = DB::table('time_entries')->where('client_id', $arranged['client'])->whereNull('invoice_id')->count();

    if ($free > 0) {
        $defects[] = "{$free} entries are held by no draft";
    }

    $lineTotal = 0;
    $drafts = DB::table('invoices')->where('client_id', $arranged['client'])->pluck('id')->all();

    foreach ($drafts as $id) {
        $lines = (int) DB::table('invoice_item_sources AS s')
            ->join('invoice_items AS i', 'i.id', '=', 's.invoice_item_id')
            ->where('i.invoice_id', $id)
            ->sum('s.source_seconds');
        $held = (int) DB::table('time_entries')->where('invoice_id', $id)->sum('duration_seconds');

        $lineTotal += $lines;

        if ($lines !== $held) {
            $defects[] = "the draft {$id} bills {$lines} seconds on its lines but holds entries of {$held} seconds";
        }
    }

    if ($lineTotal !== $arranged['seconds']) {
        $defects[] = "the lines of all drafts carry {$lineTotal} seconds, the entries have {$arranged['seconds']}";
    }

    return $defects;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = invoiceReservationCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows of its own behind');
});

it('puts every entry in exactly one draft when 4 processes create drafts from the same 24 entries at once', function () {
    $arranged = invoiceReservationArrange();

    $results = invoiceReservationRun($arranged, 'locked');

    expect(invoiceReservationDefects($results, $arranged))->toBe([]);

    // Every worker really ran, behind the same barrier, and every entry is held by one of the four drafts.
    $holders = DB::table('time_entries')->where('client_id', $arranged['client'])->distinct()->pluck('invoice_id')->all();

    expect(collect($results)->pluck('summary.pid')->unique()->count())->toBe(INVOICE_RESERVATION_WORKERS)
        ->and(DB::table('invoices')->where('client_id', $arranged['client'])->count())->toBe(INVOICE_RESERVATION_WORKERS)
        ->and(DB::table('time_entries')->where('client_id', $arranged['client'])->count())->toBe(INVOICE_RESERVATION_ENTRIES)
        ->and($holders)->not->toContain(null)
        ->and(DB::table('time_entries')->where('client_id', $arranged['client'])->whereNotNull('invoice_id')->count())->toBe(INVOICE_RESERVATION_ENTRIES);
});

it('detects the defect when the claim reads without a lock (mutation run with UnlockedEntryReservation)', function () {
    $found = [];
    $rounds = 0;

    for ($attempt = 1; $attempt <= INVOICE_RESERVATION_MUTATION_ROUNDS && $found === []; $attempt++) {
        $arranged = invoiceReservationArrange();

        $results = invoiceReservationRun($arranged, 'unlocked');
        $rounds++;

        // Guard against a vacuous pass: every worker ran and said what it did.
        foreach ($results as $i => $result) {
            expect($result['summary'])->not->toBeNull("worker {$i} produced no summary: {$result['stderr']}");
        }

        $found = invoiceReservationDefects($results, $arranged);

        expect(invoiceReservationCleanup())->toBe(0, 'the mutation round left rows of its own behind');
    }

    expect($found)->not->toBe([], "the harness did not notice the missing lock in {$rounds} rounds");
});
