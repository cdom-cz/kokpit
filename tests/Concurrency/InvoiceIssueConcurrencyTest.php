<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\NumberPattern;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\InvoiceFixtures;

/*
 * Real parallel PHP processes issuing invoices at the same moment (IN-01, D-10, FND-05).
 *
 * Eight workers issue six committed drafts each of one fictional client. In the run that matters, every third
 * allocation of a worker throws after the number was taken, so failures are mixed into the series: the issued numbers
 * must still be contiguous with no duplicate and no gap, every failed draft is still a draft without a number, and
 * the counter must continue at the next number. The same harness run with an allocator without the row lock (the
 * worker variant `unlocked` binds Tests\Support\UnlockedSequenceAllocator) must show a defect, otherwise a clean run
 * proves nothing.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. Issued rows are guarded by triggers
 * (an issued row accepts no write at all), so the cleanup disables the user triggers of the invoice tables inside one
 * transaction, deletes the rows of the test's own fictional client, enables them again and asserts that every
 * trigger of those tables is enabled. The supplier and bank settings and the counter row the test touched are put
 * back as they were.
 */

const INVOICE_ISSUE_WORKERS = 8;
const INVOICE_ISSUE_PER_WORKER = 6;
const INVOICE_ISSUE_FAIL_EVERY = 3;
const INVOICE_ISSUE_BARRIER_SECONDS = 5.0;
const INVOICE_ISSUE_TIMEOUT_SECONDS = 180;
const INVOICE_ISSUE_MUTATION_ROUNDS = 3;

/** The guarded tables the cleanup has to open, children first. */
const INVOICE_ISSUE_GUARDED_TABLES = ['invoice_item_sources', 'invoice_items', 'invoice_admin', 'invoices'];

/**
 * Bookkeeping for the rows committed by the running test.
 */
function invoiceIssueScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'client' => null,
        'admin' => null,
        'role_created' => false,
        'settings' => null,
        'counter_key' => null,
        'counter_before' => null,
    ]);
}

/**
 * Midday of today in Prague, the instant the numbering reads its series from.
 */
function invoiceIssueNoon(): CarbonImmutable
{
    return CarbonImmutable::now('Europe/Prague')->setTime(12, 0);
}

function invoiceIssuePattern(): NumberPattern
{
    // Settings rows are Admin and system only, and the parent process has no signed-in user.
    return app(PartnerContext::class)->runAsSystem(static fn (): NumberPattern => app(NumberingSettings::class)->patternFor(DocumentKind::Invoice));
}

/**
 * Commits an Admin, a fictional client, the supplier and bank settings the issue needs and 48 drafts.
 *
 * @return array{admin: string, client: string, assignments: list<list<string>>, counter_start: int}
 */
function invoiceIssueArrange(): array
{
    $scratch = invoiceIssueScratch();

    if ($scratch['settings'] === null) {
        $scratch['settings'] = DB::table('settings')->whereIn('group', ['supplier', 'bank'])->get(['id', 'payload'])->all();
    }

    $scratch['role_created'] = $scratch['role_created'] || ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    InvoiceFixtures::configureSupplier();

    $client = InvoiceFixtures::client();
    $scratch['client'] = $client->id;

    $key = invoiceIssuePattern()->scopeKey(invoiceIssueNoon());
    $scratch['counter_key'] = $key;
    $scratch['counter_before'] = DB::table('number_sequences')->where('scope_key', $key)->value('next_value');

    $assignments = [];

    for ($worker = 0; $worker < INVOICE_ISSUE_WORKERS; $worker++) {
        $ids = [];

        for ($i = 0; $i < INVOICE_ISSUE_PER_WORKER; $i++) {
            $ids[] = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '1000.00')], InvoiceKind::Invoice, $admin)->id;
        }

        $assignments[] = $ids;
    }

    return [
        'admin' => $admin->id,
        'client' => $client->id,
        'assignments' => $assignments,
        'counter_start' => $scratch['counter_before'] === null ? 1 : (int) $scratch['counter_before'],
    ];
}

/**
 * Removes everything the test committed and puts the settings and the counter back. The guarded invoice tables are
 * opened inside one transaction, so a failure leaves their triggers as they were. Returns the number of rows of its
 * own that are left behind plus the number of triggers that are not enabled (zero when the cleanup is complete).
 */
function invoiceIssueCleanup(): int
{
    $scratch = invoiceIssueScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];
    $key = $scratch['counter_key'];

    if ($client !== null) {
        DB::transaction(static function () use ($client): void {
            foreach (INVOICE_ISSUE_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            }

            $invoiceIds = DB::table('invoices')->where('client_id', $client)->pluck('id')->all();
            $itemIds = DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();

            DB::table('invoice_item_sources')->whereIn('invoice_item_id', $itemIds)->delete();
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->update(['credited_item_id' => null]);
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoice_admin')->whereIn('invoice_id', $invoiceIds)->delete();

            // The PDF of each issued document: the document row, its media row and the stored object (plan 10-20).
            $documentIds = DB::table('documents')->where('client_id', $client)->pluck('id')->all();

            foreach (DB::table('media')->where('model_type', 'document')->whereIn('model_id', $documentIds)->get(['id', 'disk']) as $media) {
                Storage::disk($media->disk)->deleteDirectory((string) $media->id);
            }

            DB::table('media')->where('model_type', 'document')->whereIn('model_id', $documentIds)->delete();
            DB::table('activity_log')->whereIn('subject_id', $documentIds)->delete();
            DB::table('documents')->whereIn('id', $documentIds)->delete();
            DB::table('activity_log')->whereIn('subject_id', [...$invoiceIds, ...$itemIds])->delete();
            DB::table('invoices')->where('client_id', $client)->delete();

            foreach (INVOICE_ISSUE_GUARDED_TABLES as $table) {
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

    if ($key !== null) {
        if ($scratch['counter_before'] === null) {
            DB::table('number_sequences')->where('scope_key', $key)->delete();
        } else {
            DB::table('number_sequences')->where('scope_key', $key)->update(['next_value' => $scratch['counter_before']]);
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
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += invoiceIssueDisabledTriggers();

    $scratch['client'] = $scratch['admin'] = $scratch['counter_key'] = $scratch['counter_before'] = null;
    $scratch['role_created'] = false;
    $scratch['settings'] = null;

    return $left;
}

/**
 * The number of triggers on the guarded invoice tables, and on the time entries and payments next to them, that are
 * not enabled. Zero when every guard is in force.
 */
function invoiceIssueDisabledTriggers(): int
{
    $tables = [...INVOICE_ISSUE_GUARDED_TABLES, 'invoice_payments', 'invoice_emails', 'time_entries'];
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));

    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ({$placeholders}) AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
        $tables,
    )->n;
}

/**
 * Starts one worker per assignment behind one shared barrier and waits for all of them.
 *
 * @param  list<list<string>>  $assignments  the draft ids of each worker
 * @param  'locked'|'failing'|'unlocked'  $variant
 * @return list<array{exit: int|null, lines: list<array<string, mixed>>, stderr: string}>
 */
function invoiceIssueRun(string $adminId, array $assignments, string $variant): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + INVOICE_ISSUE_BARRIER_SECONDS, 6, '.', '');

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
    foreach ($assignments as $ids) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/invoice-worker.php', $barrier, $variant, $adminId, implode(',', $ids)],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(INVOICE_ISSUE_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();

        $lines = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['draft'], $decoded['result'])) {
                $lines[] = $decoded;
            }
        }

        $results[] = ['exit' => $process->getExitCode(), 'lines' => $lines, 'stderr' => $process->getErrorOutput()];
    }

    return $results;
}

/**
 * What the series looks like after a run, as readable defects (empty when it is contiguous and gap-free): a worker
 * that did not account for all of its drafts, a failure that was not planned, a number that is not the next one in
 * the series, a duplicate, a failed draft that carries a number or is no longer a draft, and a counter that does not
 * continue right after the last number.
 *
 * @param  list<array{exit: int|null, lines: list<array<string, mixed>>, stderr: string}>  $results
 * @param  array{admin: string, client: string, assignments: list<list<string>>, counter_start: int}  $arranged
 * @return list<string>
 */
function invoiceIssueDefects(array $results, array $arranged): array
{
    $defects = [];
    $reported = [];
    $failedIds = [];

    foreach ($results as $i => $result) {
        if (count($result['lines']) !== count($arranged['assignments'][$i])) {
            $defects[] = "worker {$i} reported ".count($result['lines']).' of '.count($arranged['assignments'][$i])." drafts: {$result['stderr']}";
        }

        foreach ($result['lines'] as $line) {
            if ($line['result'] === 'issued') {
                $reported[(string) $line['draft']] = (string) $line['number'];

                continue;
            }

            $failedIds[] = (string) $line['draft'];

            if (($line['planned'] ?? false) !== true) {
                $defects[] = 'unplanned failure: '.(string) ($line['error'] ?? '');
            }
        }
    }

    $issued = DB::table('invoices')->where('client_id', $arranged['client'])->where('state', 'issued')->orderBy('number')->get(['id', 'number']);
    $numbers = $issued->pluck('number')->map(static fn (mixed $number): string => (string) $number)->all();

    $pattern = invoiceIssuePattern();
    $expected = [];
    for ($n = 0; $n < count($numbers); $n++) {
        $expected[] = $pattern->format($arranged['counter_start'] + $n, invoiceIssueNoon());
    }

    if ($numbers !== $expected) {
        $defects[] = 'the issued numbers are not contiguous from the start of the series: '.implode(', ', $numbers);
    }

    if (count(array_unique($numbers)) !== count($numbers)) {
        $defects[] = 'a number was issued twice';
    }

    foreach ($issued as $row) {
        if (($reported[$row->id] ?? null) !== $row->number) {
            $defects[] = "the draft {$row->id} was reported with a different number than the one stored";
        }
    }

    foreach ($failedIds as $id) {
        $row = DB::table('invoices')->where('id', $id)->first(['state', 'number']);

        if ($row === null || $row->state !== 'draft' || $row->number !== null) {
            $defects[] = "the failed draft {$id} is not a draft without a number";
        }
    }

    $counter = DB::table('number_sequences')->where('scope_key', invoiceIssueScratch()['counter_key'])->value('next_value');
    $next = $arranged['counter_start'] + count($numbers);

    if ($counter === null ? $next !== 1 : (int) $counter !== $next) {
        $defects[] = 'the counter does not continue at '.$next.' but at '.($counter ?? 'nothing');
    }

    return $defects;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = invoiceIssueCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('keeps the issued numbers contiguous when 8 processes issue at once and every third issue fails after its number was allocated', function () {
    $arranged = invoiceIssueArrange();
    $total = INVOICE_ISSUE_WORKERS * INVOICE_ISSUE_PER_WORKER;
    $plannedFailures = INVOICE_ISSUE_WORKERS * intdiv(INVOICE_ISSUE_PER_WORKER, INVOICE_ISSUE_FAIL_EVERY);

    $results = invoiceIssueRun($arranged['admin'], $arranged['assignments'], 'failing');

    expect(invoiceIssueDefects($results, $arranged))->toBe([]);

    // Every worker really ran and the failures really were mixed in.
    $issued = DB::table('invoices')->where('client_id', $arranged['client'])->where('state', 'issued')->count();
    $drafts = DB::table('invoices')->where('client_id', $arranged['client'])->where('state', 'draft')->count();
    $pids = collect($results)->flatMap(static fn (array $result): array => array_column($result['lines'], 'pid'))->unique()->count();

    expect($pids)->toBe(INVOICE_ISSUE_WORKERS)
        ->and($issued)->toBe($total - $plannedFailures)
        ->and($drafts)->toBe($plannedFailures)
        ->and(DB::table('invoices')->where('client_id', $arranged['client'])->whereNotNull('number')->count())->toBe($issued);
});

it('detects the defect when the allocator has no row lock (mutation run with UnlockedSequenceAllocator)', function () {
    $found = [];
    $rounds = 0;

    for ($attempt = 1; $attempt <= INVOICE_ISSUE_MUTATION_ROUNDS && $found === []; $attempt++) {
        $arranged = invoiceIssueArrange();

        $results = invoiceIssueRun($arranged['admin'], $arranged['assignments'], 'unlocked');
        $rounds++;

        // Guard against a vacuous pass: every worker ran and accounted for all of its drafts.
        foreach ($results as $i => $result) {
            expect($result['lines'])->toHaveCount(count($arranged['assignments'][$i]), "worker {$i} did not account for its drafts: {$result['stderr']}");
        }

        $found = invoiceIssueDefects($results, $arranged);

        expect(invoiceIssueCleanup())->toBe(0, 'the mutation round left rows or disabled triggers behind');
    }

    expect($found)->not->toBe([], "the harness did not notice the missing row lock in {$rounds} rounds");
});
