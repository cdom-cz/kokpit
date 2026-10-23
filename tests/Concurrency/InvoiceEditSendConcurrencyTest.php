<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Actions\IssueDocument;
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
 * An edit of an open issued invoice and the queueing of its document e-mail in two real parallel processes (IN-14, A8,
 * research Pitfall 3).
 *
 * The edit keeps the invoice row locked for a while (a slow edit of a big document); the queue process starts a fixed
 * time after the shared barrier, so over the rounds it arrives before, during and after the edit. With the invoice row
 * lock in both paths exactly two outcomes exist: the edit commits first and the e-mail is queued after it, on the
 * document as the edit left it, or the e-mail is queued first and the edit is refused as `email_in_flight`. What must
 * never happen is both succeeding with the e-mail committed before the edit, or the queue deciding on the document as it
 * was before an edit that committed before the queue did. The same harness run with a queue Action that reads the
 * document without the lock (the worker variant `unlocked` binds Tests\Support\UnlockedQueueInvoiceEmail) must show a
 * defect, otherwise a clean run proves nothing.
 *
 * Note on what the explicit lock buys: the queued row has a foreign key to the invoice, and its check takes
 * FOR KEY SHARE, which the FOR UPDATE of the edit blocks until the edit commits. The database therefore already orders
 * the commits, with or without the explicit read lock. What the explicit lock adds is the freshness of the read: without
 * it the Action reads the committed row before it waits at the insert, and queues on a stale document.
 *
 * Like the other concurrency suites this file has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions. Guarded invoice rows are protected by
 * triggers, so the cleanup disables the user triggers of the invoice tables inside one transaction, deletes the rows of
 * the test's own fictional client, enables them again and asserts that every trigger of those tables is enabled. The
 * supplier and bank settings and the counter row the test touched are put back as they were.
 */

const INVOICE_EDIT_BARRIER_SECONDS = 5.0;
const INVOICE_EDIT_TIMEOUT_SECONDS = 120;
const INVOICE_EDIT_MUTATION_ROUNDS = 6;

/** Seconds after the barrier at which the queue process starts, one per round. */
const INVOICE_EDIT_QUEUE_DELAYS = [0.0, 0.35, 0.7, 1.05, 1.4];

/** The guarded tables the cleanup has to open, children first. */
const INVOICE_EDIT_GUARDED_TABLES = ['invoice_item_sources', 'invoice_items', 'invoice_admin', 'invoice_emails', 'invoices'];

/**
 * Bookkeeping for the rows committed by the running test.
 */
function invoiceEditScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'clients' => [],
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
function invoiceEditNoon(): CarbonImmutable
{
    return CarbonImmutable::now('Europe/Prague')->setTime(12, 0);
}

function invoiceEditPattern(): NumberPattern
{
    // Settings rows are Admin and system only, and the parent process has no signed-in user.
    return app(PartnerContext::class)->runAsSystem(static fn (): NumberPattern => app(NumberingSettings::class)->patternFor(DocumentKind::Invoice));
}

/**
 * Commits the Admin once and one fictional client with one issued invoice of one line per call. `quantity` is the
 * stored quantity of the line before the edit.
 *
 * @return array{admin: string, client: string, invoice: string, quantity: string}
 */
function invoiceEditArrange(): array
{
    $scratch = invoiceEditScratch();

    if ($scratch['admin'] === null) {
        $scratch['settings'] = DB::table('settings')->whereIn('group', ['supplier', 'bank'])->get(['id', 'payload'])->all();
        $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
        Role::findOrCreate(RoleName::Admin->value, 'web');

        $admin = User::factory()->create(['email' => exampleEmail()]);
        $admin->assignRole(RoleName::Admin->value);
        $scratch['admin'] = $admin->id;

        InvoiceFixtures::configureSupplier();

        $key = invoiceEditPattern()->scopeKey(invoiceEditNoon());
        $scratch['counter_key'] = $key;
        $scratch['counter_before'] = DB::table('number_sequences')->where('scope_key', $key)->value('next_value');
    }

    $admin = User::query()->findOrFail($scratch['admin']);
    auth()->setUser($admin);

    $client = InvoiceFixtures::client();
    $scratch['clients'] = [...$scratch['clients'], $client->id];

    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '1000.00')], InvoiceKind::Invoice, $admin);
    $invoice = app(IssueDocument::class)->handle($admin, $draft);

    $quantity = (string) DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('position')->value('quantity');

    return ['admin' => $admin->id, 'client' => $client->id, 'invoice' => $invoice->id, 'quantity' => $quantity];
}

/**
 * Removes everything the test committed and puts the settings and the counter back. Returns the number of rows of its
 * own that are left behind plus the number of triggers that are not enabled (zero when the cleanup is complete).
 */
function invoiceEditCleanup(): int
{
    $scratch = invoiceEditScratch();
    $clients = $scratch['clients'];
    $admin = $scratch['admin'];
    $key = $scratch['counter_key'];

    if ($clients !== []) {
        DB::transaction(static function () use ($clients): void {
            foreach (INVOICE_EDIT_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            }

            $invoiceIds = DB::table('invoices')->whereIn('client_id', $clients)->pluck('id')->all();
            $itemIds = DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->pluck('id')->all();

            DB::table('invoice_item_sources')->whereIn('invoice_item_id', $itemIds)->delete();
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->update(['credited_item_id' => null]);
            DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoice_emails')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoice_admin')->whereIn('invoice_id', $invoiceIds)->delete();

            // The PDF of each issued document: the document row, its media row and the stored object.
            $documentIds = DB::table('documents')->whereIn('client_id', $clients)->pluck('id')->all();

            foreach (DB::table('media')->where('model_type', 'document')->whereIn('model_id', $documentIds)->get(['id', 'disk']) as $media) {
                Storage::disk($media->disk)->deleteDirectory((string) $media->id);
            }

            DB::table('media')->where('model_type', 'document')->whereIn('model_id', $documentIds)->delete();
            DB::table('activity_log')->whereIn('subject_id', $documentIds)->delete();
            DB::table('documents')->whereIn('id', $documentIds)->delete();
            DB::table('activity_log')->whereIn('subject_id', [...$invoiceIds, ...$itemIds])->delete();
            DB::table('invoices')->whereIn('client_id', $clients)->delete();

            foreach (INVOICE_EDIT_GUARDED_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
        });

        DB::table('contacts')->whereIn('client_id', $clients)->delete();
        DB::table('activity_log')->whereIn('subject_id', $clients)->delete();
        DB::table('clients')->whereIn('id', $clients)->delete();
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
    auth()->forgetGuards();

    $left = 0;
    $left += $clients === [] ? 0 : DB::table('invoices')->whereIn('client_id', $clients)->count();
    $left += $clients === [] ? 0 : DB::table('clients')->whereIn('id', $clients)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();
    $left += invoiceEditDisabledTriggers();

    $scratch['clients'] = [];
    $scratch['admin'] = $scratch['counter_key'] = $scratch['counter_before'] = $scratch['settings'] = null;
    $scratch['role_created'] = false;

    return $left;
}

/**
 * The number of triggers on the guarded invoice tables and on the payments and time entries next to them that are not
 * enabled. Zero when every guard is in force.
 */
function invoiceEditDisabledTriggers(): int
{
    $tables = [...INVOICE_EDIT_GUARDED_TABLES, 'invoice_payments', 'time_entries'];
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));

    return (int) DB::selectOne(
        "SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname IN ({$placeholders}) AND NOT t.tgisinternal AND t.tgenabled <> 'O'",
        $tables,
    )->n;
}

/**
 * Starts the edit process and the queue process of one invoice behind one shared barrier and waits for both.
 *
 * @param  'locked'|'unlocked'  $variant  the queue Action the queue process runs
 * @param  array{admin: string, client: string, invoice: string, quantity: string}  $arranged
 * @return array{edit: array{exit: int|null, line: array<string, mixed>|null, stderr: string}, queue: array{exit: int|null, line: array<string, mixed>|null, stderr: string}}
 */
function invoiceEditRun(array $arranged, string $variant, float $queueDelay): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + INVOICE_EDIT_BARRIER_SECONDS, 6, '.', '');

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
        'KOKPIT_QUEUE_DELAY' => number_format($queueDelay, 3, '.', ''),
    ];

    $processes = [];
    foreach (['edit', 'queue'] as $role) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/invoice-edit-worker.php', $barrier, $role, $variant, $arranged['admin'], $arranged['invoice']],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(INVOICE_EDIT_TIMEOUT_SECONDS);
        $process->start();
        $processes[$role] = $process;
    }

    $results = [];
    foreach ($processes as $role => $process) {
        $process->wait();

        $line = null;
        foreach (explode("\n", trim($process->getOutput())) as $text) {
            $decoded = json_decode($text, true);

            if (is_array($decoded) && ($decoded['role'] ?? null) === $role && isset($decoded['result'], $decoded['at'])) {
                $line = $decoded;
            }
        }

        $results[$role] = ['exit' => $process->getExitCode(), 'line' => $line, 'stderr' => $process->getErrorOutput()];
    }

    /** @var array{edit: array{exit: int|null, line: array<string, mixed>|null, stderr: string}, queue: array{exit: int|null, line: array<string, mixed>|null, stderr: string}} $results */
    return $results;
}

/**
 * What one round looks like as readable defects (empty when the edit and the queue were serialised): a process that did
 * not report, an outcome that is neither of the two allowed, both succeeding with the e-mail committed before the edit,
 * and rows that disagree with the reports.
 *
 * @param  array{edit: array{exit: int|null, line: array<string, mixed>|null, stderr: string}, queue: array{exit: int|null, line: array<string, mixed>|null, stderr: string}}  $results
 * @param  array{admin: string, client: string, invoice: string, quantity: string}  $arranged
 * @return list<string>
 */
function invoiceEditDefects(array $results, array $arranged): array
{
    $edit = $results['edit']['line'];
    $queue = $results['queue']['line'];

    if ($edit === null || $queue === null) {
        return ["a worker did not report: edit {$results['edit']['stderr']} queue {$results['queue']['stderr']}"];
    }

    $defects = [];
    $emails = (int) DB::table('invoice_emails')->where('invoice_id', $arranged['invoice'])->count();
    $quantity = (string) DB::table('invoice_items')->where('invoice_id', $arranged['invoice'])->orderBy('position')->value('quantity');

    if ($queue['result'] !== 'ok') {
        $defects[] = 'the queue failed: '.(string) ($queue['error'] ?? '');
    }

    if ($edit['result'] === 'failed') {
        $defects[] = 'the edit failed: '.(string) ($edit['error'] ?? '');
    }

    if ($edit['result'] === 'refused' && ! str_contains((string) $edit['error'], trim(json_encode(__('invoices.errors.email_in_flight'), JSON_UNESCAPED_UNICODE), '"'))) {
        $defects[] = 'the edit was refused for another reason than email_in_flight: '.(string) $edit['error'];
    }

    if ($edit['result'] === 'ok' && $queue['result'] === 'ok' && $queue['at'] < $edit['at']) {
        $defects[] = 'the e-mail was queued and committed while the edit was still open, and both succeeded';
    }

    if ($edit['result'] === 'refused' && $quantity !== $arranged['quantity']) {
        $defects[] = "the edit was refused but the line changed to {$quantity}";
    }

    // The decision of the queue (the document is issued, has linked time) must be made on the committed result of the
    // edit: a queue that read before it waited for the edit decided on a document that no longer exists.
    $total = (int) DB::table('invoices')->where('id', $arranged['invoice'])->value('total_minor');

    if ($queue['result'] === 'ok' && $queue['seen_total'] !== $total) {
        $defects[] = "the queue decided on a total of {$queue['seen_total']} but the document was left with {$total}";
    }

    if ($edit['result'] === 'ok' && (float) $quantity !== 2.0) {
        $defects[] = "the edit succeeded but the line is {$quantity}";
    }

    if ($emails !== ($queue['result'] === 'ok' ? 1 : 0)) {
        $defects[] = "the queue reported {$queue['result']} but {$emails} e-mail rows exist";
    }

    return $defects;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = invoiceEditCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows or disabled triggers behind');
});

it('serialises an edit and the queueing of the document e-mail: either the edit commits first or it is refused as email_in_flight', function () {
    $queuedAfterEdit = 0;
    $editRefused = 0;

    foreach (INVOICE_EDIT_QUEUE_DELAYS as $round => $delay) {
        $arranged = invoiceEditArrange();

        $results = invoiceEditRun($arranged, 'locked', $delay);

        expect(invoiceEditDefects($results, $arranged))->toBe([], "round {$round} (queue delay {$delay} s)");

        $edit = $results['edit']['line'];
        $queue = $results['queue']['line'];

        if ($edit['result'] === 'refused') {
            $editRefused++;
        } elseif ($queue['at'] >= $edit['at']) {
            $queuedAfterEdit++;
        }
    }

    // The window was really hit: some rounds had the queue waiting for the edit.
    expect($queuedAfterEdit)->toBeGreaterThan(0, 'no round had the queue arrive while the edit held the invoice');
});

it('detects the defect when the queue reads the document without the row lock (mutation run with UnlockedQueueInvoiceEmail)', function () {
    $found = [];
    $rounds = 0;

    for ($attempt = 0; $attempt < INVOICE_EDIT_MUTATION_ROUNDS && $found === []; $attempt++) {
        $arranged = invoiceEditArrange();

        $results = invoiceEditRun($arranged, 'unlocked', INVOICE_EDIT_QUEUE_DELAYS[($attempt % (count(INVOICE_EDIT_QUEUE_DELAYS) - 1)) + 1]);
        $rounds++;

        // Guard against a vacuous pass: both processes ran and reported.
        expect($results['edit']['line'])->not->toBeNull("the edit did not report: {$results['edit']['stderr']}")
            ->and($results['queue']['line'])->not->toBeNull("the queue did not report: {$results['queue']['stderr']}");

        $found = invoiceEditDefects($results, $arranged);
    }

    expect($found)->not->toBe([], "the harness did not notice the missing row lock in {$rounds} rounds");
});
