<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\TimeTracking\Actions\CancelEntriesBilling;
use App\Domain\TimeTracking\Actions\DeleteTimeEntry;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Enums\BillingBadge;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use App\Filament\RelationManagers\TimeEntryHistoryRelationManager;
use App\Filament\Resources\TimeEntryResource\Pages\EditTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Billing a selection of time entries and cancelling it (TI-05, D-06). Every
 * value is fictional; the descriptions are canaries assembled at run time.
 */

const LOCK_MESSAGE = 'Záznam je vyfakturovaný a nelze ho upravit. Nejdřív zrušte fakturaci.';

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function lockSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A stored finished entry of the user, written by the factory (a system write).
 *
 * @param  array<string, mixed>  $attributes
 */
function lockEntry(User $user, string $start, string $end, array $attributes = []): TimeEntry
{
    return lockSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh());
}

/**
 * Three finished billable entries of 3600, 1800 and 905 seconds (6305 in all).
 *
 * @return list<TimeEntry>
 */
function lockThree(User $user): array
{
    return [
        lockEntry($user, '2026-10-12 08:00:00', '2026-10-12 09:00:00'),
        lockEntry($user, '2026-10-12 09:30:00', '2026-10-12 10:00:00'),
        lockEntry($user, '2026-10-12 11:00:00', '2026-10-12 11:15:05'),
    ];
}

/**
 * The stored row, read again as a system run.
 */
function lockReload(TimeEntry $entry): TimeEntry
{
    return lockSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

/**
 * The ids of the entries.
 *
 * @param  list<TimeEntry>  $entries
 * @return list<string>
 */
function lockIds(array $entries): array
{
    return array_map(static fn (TimeEntry $entry): string => $entry->id, $entries);
}

/**
 * The history rows of the update events of the entries, oldest first.
 *
 * @param  list<string>  $ids
 * @return list<array{subject_id: string, event: string, attribute_changes: array{attributes?: array<string, mixed>, old?: array<string, mixed>}, raw: string}>
 */
function lockHistory(array $ids): array
{
    $rows = DB::table('activity_log')
        ->where('subject_type', 'time_entry')
        ->whereIn('subject_id', $ids)
        ->where('event', 'updated')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();

    return $rows->map(static fn (object $row): array => [
        'subject_id' => (string) $row->subject_id,
        'event' => (string) $row->event,
        'attribute_changes' => json_decode((string) $row->attribute_changes, true) ?: [],
        'raw' => json_encode($row),
    ])->all();
}

/*
 * Tracer: bill a selection, lock it at every layer, cancel it, audit it.
 */

it('bills a selection, locks it against edit, delete and raw SQL, and unlocks it only by cancelling', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    $entries = lockThree($admin);
    $ids = lockIds($entries);

    $result = app(MarkEntriesBilled::class)->handle($admin, $ids);

    expect($result)->toBe([
        'billed' => 3,
        'billed_seconds' => 6305,
        'skipped_non_billable' => 0,
        'skipped_billed' => 0,
        'skipped_running' => 0,
        'skipped_reserved' => 0,
    ]);

    foreach ($entries as $entry) {
        $stored = lockReload($entry);

        expect($stored->billing_state->value)->toBe('billed')
            ->and($stored->billed_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-14 10:00:00');
    }

    // The application refuses an edit and a delete.
    expect(fn () => app(UpdateTimeEntry::class)->handle($admin, $entries[0], ['description' => 'Changed']))
        ->toThrow(DomainException::class, LOCK_MESSAGE)
        ->and(fn () => app(DeleteTimeEntry::class)->handle($admin, $entries[0]))
        ->toThrow(DomainException::class, LOCK_MESSAGE);

    // The database refuses raw SQL (inside a savepoint, so the test transaction survives).
    $refusal = null;

    try {
        DB::transaction(static fn () => DB::table('time_entries')->where('id', $entries[0]->id)->update(['description' => 'Raw edit']));
    } catch (QueryException $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(QueryException::class)
        ->and(TimeEntryInput::isFrozenRowRefusal($refusal))->toBeTrue()
        ->and(lockReload($entries[0])->description)->toBe($entries[0]->description);

    // Cancelling is the only way back, and an edit then succeeds.
    expect(app(CancelEntriesBilling::class)->handle($admin, $ids))->toBe(3);

    foreach ($entries as $entry) {
        $stored = lockReload($entry);

        expect($stored->billing_state->value)->toBe('unbilled')
            ->and($stored->billed_at)->toBeNull();
    }

    $edited = app(UpdateTimeEntry::class)->handle($admin, $entries[0], ['description' => 'Free again']);

    expect($edited->description)->toBe('Free again');
});

it('writes one allowlisted history row per entry and per action, never the description', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    $canary = Canary::canary('description');
    $entries = [
        lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => $canary]),
        lockEntry($admin, '2026-10-12 09:30:00', '2026-10-12 10:00:00', ['description' => $canary]),
        lockEntry($admin, '2026-10-12 11:00:00', '2026-10-12 11:15:05', ['description' => $canary]),
    ];
    $ids = lockIds($entries);
    $before = count(lockHistory($ids));

    app(MarkEntriesBilled::class)->handle($admin, $ids);

    $afterBilling = lockHistory($ids);

    expect($afterBilling)->toHaveCount($before + 3);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 11:00:00', 'UTC'));
    app(CancelEntriesBilling::class)->handle($admin, $ids);

    $history = lockHistory($ids);

    expect($history)->toHaveCount($before + 6);

    $allowed = ['client_id', 'project_id', 'task_id', 'started_at', 'ended_at', 'billable', 'billing_state', 'billed_at'];

    foreach (array_slice($history, $before) as $row) {
        $changes = $row['attribute_changes'];

        expect(array_keys($changes['attributes'] ?? []))->each->toBeIn($allowed)
            ->and(array_keys($changes['old'] ?? []))->each->toBeIn($allowed)
            ->and($changes['attributes'] ?? [])->toHaveKey('billing_state')
            ->and($row['raw'])->not->toContain($canary);
    }

    $perEntry = array_count_values(array_column(array_slice($history, $before), 'subject_id'));

    expect($perEntry)->toHaveCount(3)->and(array_unique(array_values($perEntry)))->toBe([2]);

    $billing = array_slice($history, $before, 3);
    $unbilling = array_slice($history, $before + 3, 3);

    foreach ($billing as $row) {
        expect($row['attribute_changes']['old']['billing_state'] ?? null)->toBe('unbilled')
            ->and($row['attribute_changes']['attributes']['billing_state'] ?? null)->toBe('billed');
    }

    foreach ($unbilling as $row) {
        expect($row['attribute_changes']['old']['billing_state'] ?? null)->toBe('billed')
            ->and($row['attribute_changes']['attributes']['billing_state'] ?? null)->toBe('unbilled');
    }
});

/*
 * Eligibility, skipped counts and stale selections.
 */

/**
 * A selection of one eligible, one non-billable, one already billed and one running entry.
 *
 * @return array{eligible: TimeEntry, non_billable: TimeEntry, billed: TimeEntry, running: TimeEntry}
 */
function lockMixed(User $user): array
{
    return [
        'eligible' => lockEntry($user, '2026-10-12 08:00:00', '2026-10-12 09:00:00'),
        'non_billable' => lockEntry($user, '2026-10-12 09:00:00', '2026-10-12 09:30:00', ['billable' => false]),
        'billed' => lockEntry($user, '2026-10-12 10:00:00', '2026-10-12 10:20:00', ['billing_state' => 'billed', 'billed_at' => CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC')]),
        'running' => lockEntry($user, '2026-10-12 11:00:00', ''),
    ];
}

/**
 * The number of history rows of the entries, every event included.
 *
 * @param  list<string>  $ids
 */
function lockHistoryCount(array $ids): int
{
    return DB::table('activity_log')->where('subject_type', 'time_entry')->whereIn('subject_id', $ids)->count();
}

it('bills only the eligible entry of a mixed selection and counts each skipped reason', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entries = lockMixed($admin);

    $result = app(MarkEntriesBilled::class)->handle($admin, lockIds(array_values($entries)));

    expect($result)->toBe([
        'billed' => 1,
        'billed_seconds' => 3600,
        'skipped_non_billable' => 1,
        'skipped_billed' => 1,
        'skipped_running' => 1,
        'skipped_reserved' => 0,
    ])
        ->and(lockReload($entries['eligible'])->billing_state->value)->toBe('billed')
        ->and(lockReload($entries['non_billable'])->billing_state->value)->toBe('unbilled')
        ->and(lockReload($entries['running'])->billing_state->value)->toBe('unbilled')
        ->and(lockReload($entries['running'])->billed_at)->toBeNull();
});

it('counts a running non-billable entry as running, once', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $eligible = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    $running = lockEntry($admin, '2026-10-12 11:00:00', '', ['billable' => false]);

    $result = app(MarkEntriesBilled::class)->handle($admin, lockIds([$eligible, $running]));

    expect($result['skipped_running'])->toBe(1)
        ->and($result['skipped_non_billable'])->toBe(0);
});

it('bills a finished billable entry of zero length', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 08:00:00');

    $result = app(MarkEntriesBilled::class)->handle($admin, [$entry->id]);

    expect($result['billed'])->toBe(1)
        ->and($result['billed_seconds'])->toBe(0)
        ->and(lockReload($entry)->billing_state->value)->toBe('billed');
});

it('previews the same selection with its exact seconds and writes nothing', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entries = lockMixed($admin);
    $ids = lockIds(array_values($entries));
    $history = lockHistoryCount($ids);

    expect(app(MarkEntriesBilled::class)->preview($ids))->toBe(['eligible' => 1, 'eligible_seconds' => 3600, 'skipped' => 3])
        ->and(lockReload($entries['eligible'])->billing_state->value)->toBe('unbilled')
        ->and(lockHistoryCount($ids))->toBe($history);

    // The preview and the write agree.
    $result = app(MarkEntriesBilled::class)->handle($admin, $ids);

    expect($result['billed'])->toBe(1)->and($result['billed_seconds'])->toBe(3600);

    // Billed in the meantime, the same selection previews as having nothing left.
    expect(app(MarkEntriesBilled::class)->preview($ids))->toBe(['eligible' => 0, 'eligible_seconds' => 0, 'skipped' => 4]);
});

it('previews a cancel with the billed entries and their seconds and writes nothing', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entries = lockMixed($admin);
    $ids = lockIds(array_values($entries));
    $history = lockHistoryCount($ids);

    expect(app(CancelEntriesBilling::class)->preview($ids))->toBe(['eligible' => 1, 'eligible_seconds' => 1200])
        ->and(lockReload($entries['billed'])->billing_state->value)->toBe('billed')
        ->and(lockHistoryCount($ids))->toBe($history);
});

it('refuses a selection with nothing to bill and changes nothing', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $nonBillable = lockEntry($admin, '2026-10-12 09:00:00', '2026-10-12 09:30:00', ['billable' => false]);
    $running = lockEntry($admin, '2026-10-12 11:00:00', '');
    $ids = lockIds([$nonBillable, $running]);
    $history = lockHistoryCount($ids);

    expect(fn () => app(MarkEntriesBilled::class)->handle($admin, $ids))
        ->toThrow(DomainException::class, 'V označených záznamech není nic k vyfakturování.')
        ->and(lockReload($nonBillable)->billing_state->value)->toBe('unbilled')
        ->and(lockReload($running)->billing_state->value)->toBe('unbilled')
        ->and(lockHistoryCount($ids))->toBe($history);
});

it('refuses cancelling a selection with no billed entry and changes nothing', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entries = lockThree($admin);
    $ids = lockIds($entries);
    $history = lockHistoryCount($ids);

    expect(fn () => app(CancelEntriesBilling::class)->handle($admin, $ids))
        ->toThrow(DomainException::class, 'V označených záznamech není nic, co by šlo odemknout.')
        ->and(fn () => app(CancelEntriesBilling::class)->handle($admin, []))
        ->toThrow(DomainException::class, 'V označených záznamech není nic, co by šlo odemknout.')
        ->and(lockHistoryCount($ids))->toBe($history);
});

it('ignores malformed and unknown ids and counts a duplicate once', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    $unknown = '0198a000-0000-7000-8000-000000000001';

    $result = app(MarkEntriesBilled::class)->handle($admin, ['not-a-uuid', 42, null, ['nested'], $unknown, $entry->id, $entry->id, strtoupper($entry->id)]);

    expect($result['billed'])->toBe(1)
        ->and($result['billed_seconds'])->toBe(3600)
        ->and(app(MarkEntriesBilled::class)->preview(['not-a-uuid', $unknown]))->toBe(['eligible' => 0, 'eligible_seconds' => 0, 'skipped' => 0]);

    expect(fn () => app(MarkEntriesBilled::class)->handle($admin, ['not-a-uuid', $unknown]))
        ->toThrow(DomainException::class, 'V označených záznamech není nic k vyfakturování.');
});

it('counts an entry billed by another request after the page loaded as already billed', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entries = lockThree($admin);
    $ids = lockIds($entries);

    // The page held all three; another request bills the second one first.
    app(MarkEntriesBilled::class)->handle($admin, [$entries[1]->id]);

    $result = app(MarkEntriesBilled::class)->handle($admin, $ids);

    expect($result['billed'])->toBe(2)
        ->and($result['billed_seconds'])->toBe(3600 + 905)
        ->and($result['skipped_billed'])->toBe(1);

    // A second identical call finds nothing left to bill.
    expect(fn () => app(MarkEntriesBilled::class)->handle($admin, $ids))
        ->toThrow(DomainException::class, 'V označených záznamech není nic k vyfakturování.');
});

it('unlocks only the billed entries of a mixed selection', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entries = lockMixed($admin);

    expect(app(CancelEntriesBilling::class)->handle($admin, lockIds(array_values($entries))))->toBe(1);

    $stored = lockReload($entries['billed']);

    expect($stored->billing_state->value)->toBe('unbilled')
        ->and($stored->billed_at)->toBeNull();
});

it('refuses a Partner on both Actions and changes nothing', function (): void {
    $admin = Canary::admin();
    $entries = lockMixed($admin);
    $ids = lockIds(array_values($entries));
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $this->actingAs($partner);
    $history = lockHistoryCount($ids);

    expect(fn () => app(MarkEntriesBilled::class)->handle($partner, $ids))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelEntriesBilling::class)->handle($partner, $ids))->toThrow(AuthorizationException::class)
        ->and(lockReload($entries['eligible'])->billing_state->value)->toBe('unbilled')
        ->and(lockReload($entries['billed'])->billing_state->value)->toBe('billed')
        ->and(lockHistoryCount($ids))->toBe($history);
});

it('keeps the billed_finished check as the backstop for every writer', function (string $kind): void {
    $admin = Canary::admin();
    $entry = $kind === 'running'
        ? lockEntry($admin, '2026-10-12 11:00:00', '')
        : lockEntry($admin, '2026-10-12 09:00:00', '2026-10-12 09:30:00', ['billable' => false]);

    $refusal = null;

    try {
        DB::transaction(static fn () => DB::table('time_entries')->where('id', $entry->id)->update(['billing_state' => 'billed', 'billed_at' => '2026-10-13 09:00:00']));
    } catch (QueryException $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(QueryException::class)
        ->and($refusal->getPrevious()?->getCode())->toBe('23514')
        ->and(lockReload($entry)->billing_state->value)->toBe('unbilled');
})->with(['a non-billable entry' => ['non_billable'], 'a running entry' => ['running']]);

/*
 * The lock on the Admin screens (TI-05, D-06): bulk billing with an honest confirmation, locked
 * rows, the redirect of the edit URL and the unlock from the view page.
 */

/**
 * Signs in an Admin on the admin panel.
 */
function lockScreenAdmin(): User
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = Canary::admin();
    test()->actingAs($admin);

    return $admin;
}

it('bills the eligible entries of a selection in bulk, says what it skips and locks the rows', function (): void {
    $admin = lockScreenAdmin();
    $first = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    $second = lockEntry($admin, '2026-10-12 09:30:00', '2026-10-12 10:00:00');
    $nonBillable = lockEntry($admin, '2026-10-12 11:00:00', '2026-10-12 11:30:00', ['billable' => false]);
    $selection = [$first, $second, $nonBillable];

    $list = Livewire::test(ListTimeEntries::class)
        ->mountTableBulkAction('markBilled', $selection)
        ->assertMountedActionModalSee([
            'Označit jako vyfakturované?',
            '2 záznamy s časem 1:30 se uzamknou',
            'Přeskočí se 1 záznam, který je nefakturovatelný, už vyfakturovaný nebo běží.',
        ]);

    $list->callMountedTableBulkAction()
        ->assertNotified('Označeno jako vyfakturované (2)');

    expect(lockReload($first)->billing_state->value)->toBe('billed')
        ->and(lockReload($second)->billing_state->value)->toBe('billed')
        ->and(lockReload($nonBillable)->billing_state->value)->toBe('unbilled');

    // A locked row has neither the edit nor the delete action and shows the lock badge.
    Livewire::test(ListTimeEntries::class)
        ->assertTableActionHidden('edit', $first)
        ->assertTableActionHidden('delete', $first)
        ->assertTableActionVisible('edit', $nonBillable)
        ->assertTableActionVisible('delete', $nonBillable)
        ->assertTableColumnStateSet('billing_badge', BillingBadge::Billed, $first)
        ->assertTableColumnStateSet('billing_badge', BillingBadge::NonBillable, $nonBillable)
        ->assertSee('Vyfakturováno');
});

it('shows a danger toast and changes nothing when the selection has nothing to bill or to unlock', function (): void {
    $admin = lockScreenAdmin();
    $nonBillable = lockEntry($admin, '2026-10-12 11:00:00', '2026-10-12 11:30:00', ['billable' => false]);
    $running = lockEntry($admin, '2026-10-12 12:00:00', '');
    $unbilled = lockEntry($admin, '2026-10-12 13:00:00', '2026-10-12 13:30:00');
    $history = lockHistoryCount(lockIds([$nonBillable, $running, $unbilled]));

    Livewire::test(ListTimeEntries::class)
        ->callTableBulkAction('markBilled', [$nonBillable, $running])
        ->assertNotified('V označených záznamech není nic k vyfakturování.')
        ->callTableBulkAction('cancelBilling', [$unbilled])
        ->assertNotified('V označených záznamech není nic, co by šlo odemknout.');

    expect(lockReload($nonBillable)->billing_state->value)->toBe('unbilled')
        ->and(lockReload($running)->billing_state->value)->toBe('unbilled')
        ->and(lockReload($unbilled)->billing_state->value)->toBe('unbilled')
        ->and(lockHistoryCount(lockIds([$nonBillable, $running, $unbilled])))->toBe($history);
});

it('sends the edit URL of a billed entry to its view page and shows the callout there', function (): void {
    $admin = lockScreenAdmin();
    $billed = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    app(MarkEntriesBilled::class)->handle($admin, [$billed->id]);

    $this->get('/admin/time-entries/'.$billed->id.'/edit')->assertRedirect('/admin/time-entries/'.$billed->id);

    $this->get('/admin/time-entries/'.$billed->id)
        ->assertOk()
        ->assertSee('Záznam je uzamčený')
        ->assertSee('Vyfakturovaný záznam nejde upravit ani smazat.')
        ->assertSee('Zrušit fakturaci')
        ->assertSee('Historie změn')
        ->assertDontSee('Upravit záznam')
        ->assertDontSee('Smazat záznam');
});

it('still opens the edit page of an unbilled entry', function (): void {
    $admin = lockScreenAdmin();
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');

    $this->get('/admin/time-entries/'.$entry->id.'/edit')->assertOk();
    $this->get('/admin/time-entries/'.$entry->id)
        ->assertOk()
        ->assertSee('Upravit záznam')
        ->assertDontSee('Záznam je uzamčený');
});

it('unlocks a billed entry from its view page and it is editable again', function (): void {
    $admin = lockScreenAdmin();
    $billed = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    app(MarkEntriesBilled::class)->handle($admin, [$billed->id]);

    Livewire::test(ViewTimeEntry::class, ['record' => $billed->id])
        ->assertSee('Záznam je uzamčený')
        ->assertActionVisible('cancelBilling')
        ->assertActionHidden('edit')
        ->callAction('cancelBilling')
        ->assertNotified('Fakturace byla zrušena (1)')
        ->assertDontSee('Záznam je uzamčený')
        ->assertActionHidden('cancelBilling')
        ->assertActionVisible('edit');

    expect(lockReload($billed)->billing_state->value)->toBe('unbilled');

    Livewire::test(EditTimeEntry::class, ['record' => $billed->id])->assertOk();
});

it('unlocks a selection in bulk, announces it to the timer components and asks with the right count', function (): void {
    $admin = lockScreenAdmin();
    $entries = lockThree($admin);
    app(MarkEntriesBilled::class)->handle($admin, lockIds($entries));

    Livewire::test(ListTimeEntries::class)
        ->mountTableBulkAction('cancelBilling', $entries)
        ->assertMountedActionModalSee(['Zrušit fakturaci?', 'Odemknou se 3 záznamy a vrátí se mezi nevyfakturované.'])
        ->callMountedTableBulkAction()
        ->assertNotified('Fakturace byla zrušena (3)')
        ->assertDispatched('time-entry-saved');

    foreach ($entries as $entry) {
        expect(lockReload($entry)->billing_state->value)->toBe('unbilled');
    }
});

/*
 * The entry page: the effective rate, delete and the history tab (TI-08, TI-02, D-06).
 */

it('shows the effective rate with its source on the view page, never an amount', function (): void {
    $admin = lockScreenAdmin();
    $client = Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::fromMajor('800', 'CZK')]);
    $project = app(CreateProject::class)->handle($client, ['name' => 'Example rate project', 'key' => ProjectFactory::randomKey(), 'billing_type' => 'hourly', 'hourly_rate' => '900']);
    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example rate task']);
    $task = app(UpdateTask::class)->handle($admin, $task, ['billing_type' => 'hourly', 'hourly_rate' => '1250'])->refresh();

    $onTask = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['client_id' => $client->id, 'project_id' => $project->id, 'task_id' => $task->id]);
    $clientOnly = lockEntry($admin, '2026-10-12 10:00:00', '2026-10-12 11:00:00', ['client_id' => $client->id]);
    $nonBillable = lockEntry($admin, '2026-10-12 12:00:00', '2026-10-12 13:00:00', ['client_id' => $client->id, 'billable' => false]);

    Livewire::test(ViewTimeEntry::class, ['record' => $onTask->id])
        ->assertSee('Platná sazba')
        ->assertSee(Money::fromMajor('1250', 'CZK')->format('cs').' (zdroj: úkol)', escape: false)
        ->assertDontSee('Záznam je uzamčený');

    Livewire::test(ViewTimeEntry::class, ['record' => $clientOnly->id])
        ->assertSee('Platná sazba')
        ->assertSee(Money::fromMajor('800', 'CZK')->format('cs').' (zdroj: klient)', escape: false);

    Livewire::test(ViewTimeEntry::class, ['record' => $nonBillable->id])
        ->assertDontSee('Platná sazba')
        ->assertSee('Nefakturovatelné');
});

it('shows when an entry was billed and the billing state on its page', function (): void {
    $admin = lockScreenAdmin();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:30:00', 'UTC'));
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');

    Livewire::test(ViewTimeEntry::class, ['record' => $entry->id])
        ->assertSee('Nevyfakturováno')
        ->assertDontSee('Vyfakturováno');

    app(MarkEntriesBilled::class)->handle($admin, [$entry->id]);

    // 10:30 UTC is 12:30 in Prague.
    Livewire::test(ViewTimeEntry::class, ['record' => $entry->id])
        ->assertSee('Vyfakturováno')
        ->assertSee('14. 10. 2026 12:30');
});

it('deletes an unbilled finished entry from its page after a confirmation that names the duration', function (): void {
    $admin = lockScreenAdmin();
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:30:00');

    Livewire::test(ViewTimeEntry::class, ['record' => $entry->id])
        ->mountAction('delete')
        ->assertMountedActionModalSee(['Smazat záznam?', 'Záznam s časem 1:30 se trvale smaže. Tuto akci nelze vrátit.'])
        ->callMountedAction()
        ->assertNotified('Záznam byl smazán')
        ->assertDispatched('time-entry-deleted')
        ->assertRedirect('/admin/time-entries');

    expect(lockSystem(static fn (): int => TimeEntry::query()->whereKey($entry->id)->count()))->toBe(0);
});

it('deletes an unbilled finished entry from the list and offers a running entry no delete', function (): void {
    $admin = lockScreenAdmin();
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:30:00');
    $running = lockEntry($admin, '2026-10-12 10:00:00', '');

    Livewire::test(ListTimeEntries::class)
        ->assertTableActionHidden('delete', $running)
        ->assertTableActionVisible('edit', $running)
        ->callTableAction('delete', $entry)
        ->assertNotified('Záznam byl smazán');

    expect(lockSystem(static fn (): int => TimeEntry::query()->whereKey($entry->id)->count()))->toBe(0)
        ->and(lockSystem(static fn (): int => TimeEntry::query()->whereKey($running->id)->count()))->toBe(1);
});

it('does not delete an entry that was billed after the confirmation opened', function (): void {
    $admin = lockScreenAdmin();
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:30:00');

    $page = Livewire::test(ViewTimeEntry::class, ['record' => $entry->id])->mountAction('delete');

    app(MarkEntriesBilled::class)->handle($admin, [$entry->id]);

    // The page asks again on the new request: the delete action is hidden for a billed entry, and
    // DeleteTimeEntry would refuse it anyway.
    $page->callMountedAction();

    expect(lockReload($entry)->billing_state->value)->toBe('billed')
        ->and(lockSystem(static fn (): int => TimeEntry::query()->whereKey($entry->id)->count()))->toBe(1);
});

it('lists the billing change of an entry in the history tab with the Czech label and no description', function (): void {
    $admin = lockScreenAdmin();
    $canary = Canary::canary('description');
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => $canary]);
    app(MarkEntriesBilled::class)->handle($admin, [$entry->id]);
    app(CancelEntriesBilling::class)->handle($admin, [$entry->id]);

    $activities = $entry->activitiesAsSubject()->where('event', 'updated')->get();

    expect($activities)->toHaveCount(2)
        ->and(TimeEntryHistoryRelationManager::canViewForRecord($entry, ViewTimeEntry::class))->toBeTrue();

    Livewire::test(TimeEntryHistoryRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewTimeEntry::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($activities)
        ->assertSee('Stav fakturace')
        ->assertDontSee($canary);
});

it('keeps the history tab and the rate from a Partner', function (): void {
    $admin = lockScreenAdmin();
    $entry = lockEntry($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $this->actingAs($partner);

    expect(TimeEntryHistoryRelationManager::canViewForRecord($entry, ViewTimeEntry::class))->toBeFalse();

    Livewire::test(TimeEntryHistoryRelationManager::class, ['ownerRecord' => $entry, 'pageClass' => ViewTimeEntry::class])->assertForbidden();
    $this->get('/admin/time-entries/'.$entry->id)->assertForbidden();
});

/*
 * Reservations by invoice drafts and billing by invoices (D-04, D-14, F-13, R-09-3). Documents are prepared as
 * system by raw SQL, because the issuing Actions arrive in plan 10-12; every value is fictional.
 */

const RESERVED_MESSAGE = 'Záznam je připojen ke konceptu faktury. Odpojte ho v konceptu.';

/**
 * Inserts one draft document of the client by raw SQL and returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function lockDocument(string $clientId, array $overrides = []): string
{
    $row = [
        'client_id' => $clientId,
        'kind' => 'invoice',
        'state' => 'draft',
        'currency' => 'CZK',
        'language' => 'cs',
        'created_at' => '2026-10-17 10:00:00+00',
        'updated_at' => '2026-10-17 10:00:00+00',
        ...$overrides,
    ];
    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf('insert into invoices (%s) values (%s) returning id', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * Issues the draft document by raw SQL and returns its number; a credit note gets a negative total.
 */
function lockIssue(string $documentId): string
{
    static $sequence = 0;
    $sequence++;
    $number = '2026'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

    /** @var object{kind: string} $document */
    $document = DB::selectOne('select kind from invoices where id = ?', [$documentId]);
    $creditNote = $document->kind === 'credit_note';
    $total = $creditNote ? -100000 : 100000;

    DB::update(
        'update invoices set state = \'issued\', number = ?, variable_symbol = ?, issue_date = \'2026-10-17\', due_date = ?, subtotal_minor = ?, total_minor = ?, '
        .'supplier_snapshot = ?, customer_snapshot = ?, bank_snapshot = ?, appearance_snapshot = ?, issued_at = \'2026-10-17 10:30:00+00\' where id = ?',
        [
            $number,
            $creditNote ? null : $number,
            $creditNote ? null : '2026-10-31',
            $total,
            $total,
            json_encode(['name' => 'Example Supplier s.r.o.']),
            json_encode(['name' => 'Example Customer a.s.']),
            $creditNote ? null : json_encode(['account' => 'fictional']),
            json_encode(['accent' => '#336699']),
            $documentId,
        ],
    );

    return $number;
}

/**
 * A finished billable entry of one hour of the client, written by the factory.
 */
function lockClientEntry(User $user, Client $client, string $start = '2026-10-12 08:00:00'): TimeEntry
{
    $from = CarbonImmutable::parse($start, 'UTC');

    return lockEntry($user, $start, $from->addHour()->format('Y-m-d H:i:s'), ['client_id' => $client->id]);
}

/**
 * A fictional client with a rate, so billing writes a snapshot.
 */
function lockRatedClient(): Client
{
    return lockSystem(static fn (): Client => Client::factory()->create(['currency' => 'CZK', 'hourly_rate' => Money::fromMajor('1500', 'CZK')]));
}

/**
 * Locks the entries as a caller would and runs the callback with the fresh rows.
 *
 * @param  list<TimeEntry>  $entries
 * @param  Closure(list<TimeEntry>): void  $callback
 */
function lockRows(array $entries, Closure $callback): void
{
    lockSystem(static function () use ($entries, $callback): void {
        DB::transaction(static function () use ($entries, $callback): void {
            $rows = TimeEntry::query()->whereKey(lockIds($entries))->orderBy('id')->lockForUpdate()->get();

            $callback(array_values($rows->all()));
        });
    });
}

/**
 * The SQLSTATE of the refusal a callback raises inside a savepoint, or null when it was accepted.
 */
function lockRefusal(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

it('bills the free entry of a selection and skips the one reserved by a draft', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = lockRatedClient();
    $reserved = lockClientEntry($admin, $client, '2026-10-12 08:00:00');
    $free = lockClientEntry($admin, $client, '2026-10-12 10:00:00');
    $draft = lockSystem(static fn (): string => lockDocument($client->id));
    lockRows([$reserved], static fn (array $rows) => app(EntryBilling::class)->reserve($rows, $draft));
    $ids = lockIds([$reserved, $free]);

    expect(app(MarkEntriesBilled::class)->preview($ids))->toBe(['eligible' => 1, 'eligible_seconds' => 3600, 'skipped' => 1]);

    $result = app(MarkEntriesBilled::class)->handle($admin, $ids);

    expect($result['billed'])->toBe(1)
        ->and($result['skipped_reserved'])->toBe(1)
        ->and(lockReload($free)->billing_state->value)->toBe('billed')
        ->and(lockReload($reserved)->billing_state->value)->toBe('unbilled')
        ->and(lockReload($reserved)->invoice_id)->toBe($draft)
        ->and(lockReload($reserved)->isReserved())->toBeTrue();
});

it('refuses a selection of only reserved entries and changes nothing', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = lockRatedClient();
    $reserved = lockClientEntry($admin, $client);
    $draft = lockSystem(static fn (): string => lockDocument($client->id));
    lockRows([$reserved], static fn (array $rows) => app(EntryBilling::class)->reserve($rows, $draft));
    $history = lockHistoryCount([$reserved->id]);

    expect(fn () => app(MarkEntriesBilled::class)->handle($admin, [$reserved->id]))
        ->toThrow(DomainException::class, RESERVED_MESSAGE)
        ->and(lockReload($reserved)->billing_state->value)->toBe('unbilled')
        ->and(lockReload($reserved)->invoice_id)->toBe($draft)
        ->and(lockHistoryCount([$reserved->id]))->toBe($history);
});

it('refuses to unlock an entry billed by an issued invoice, naming it, and leaves the whole selection as it was', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = lockRatedClient();
    $byInvoice = lockClientEntry($admin, $client, '2026-10-12 08:00:00');
    $byHand = lockClientEntry($admin, $client, '2026-10-12 10:00:00');
    app(MarkEntriesBilled::class)->handle($admin, [$byHand->id]);

    $number = lockSystem(static function () use ($client, $byInvoice): string {
        $invoice = lockDocument($client->id);
        lockRows([$byInvoice], static fn (array $rows) => app(EntryBilling::class)->reserve($rows, $invoice));
        $number = lockIssue($invoice);
        lockRows([$byInvoice], static fn (array $rows) => app(EntryBilling::class)->bill($rows, CarbonImmutable::parse('2026-10-17 10:30:00', 'UTC')));

        return $number;
    });
    $ids = lockIds([$byInvoice, $byHand]);
    $history = lockHistoryCount($ids);

    expect(app(CancelEntriesBilling::class)->preview($ids))->toBe(['eligible' => 0, 'eligible_seconds' => 0])
        ->and(fn () => app(CancelEntriesBilling::class)->handle($admin, $ids))
        ->toThrow(DomainException::class, 'Záznam patří k vystavené faktuře '.$number.' a nelze ho odemknout. Vystavte dobropis.');

    $stored = lockReload($byInvoice);

    expect($stored->billing_state->value)->toBe('billed')
        ->and($stored->invoice_id)->not->toBeNull()
        ->and($stored->billed_rate_minor)->toBe(150000)
        ->and($stored->billed_amount_minor)->toBe(150000)
        ->and(lockReload($byHand)->billing_state->value)->toBe('billed')
        ->and(lockHistoryCount($ids))->toBe($history);

    // The entry billed by hand alone still unlocks.
    expect(app(CancelEntriesBilling::class)->handle($admin, [$byHand->id]))->toBe(1);
});

it('releases the time of an invoice only after an issued storno credit note, and a partial one is refused by the database', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = lockRatedClient();
    $entry = lockClientEntry($admin, $client);

    $invoice = lockSystem(static function () use ($client, $entry): string {
        $invoice = lockDocument($client->id);
        lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->reserve($rows, $invoice));
        lockIssue($invoice);
        lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->bill($rows, CarbonImmutable::parse('2026-10-17 10:30:00', 'UTC')));

        return $invoice;
    });

    // No credit note: the link guard refuses the release for any writer.
    expect(lockSystem(static fn (): ?string => lockRefusal(static fn () => lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->release($rows)))))->toBe('KP001');

    // A partial credit note does not cancel the invoice.
    lockSystem(static function () use ($invoice, $client): void {
        lockIssue(lockDocument($client->id, ['kind' => 'credit_note', 'credited_invoice_id' => $invoice, 'cancels_invoice' => 'false']));
    });

    expect(lockSystem(static fn (): ?string => lockRefusal(static fn () => lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->release($rows)))))->toBe('KP001')
        ->and(lockReload($entry)->billing_state->value)->toBe('billed');

    // The storno credit note releases it, with the link and the snapshot cleared.
    lockSystem(static function () use ($invoice, $client, $entry): void {
        lockIssue(lockDocument($client->id, ['kind' => 'credit_note', 'credited_invoice_id' => $invoice, 'cancels_invoice' => 'true']));
        lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->release($rows));
    });

    $released = lockReload($entry);

    expect($released->billing_state->value)->toBe('unbilled')
        ->and($released->billed_at)->toBeNull()
        ->and($released->invoice_id)->toBeNull()
        ->and($released->billed_rate_minor)->toBeNull()
        ->and($released->billed_rate_currency)->toBeNull()
        ->and($released->billed_amount_minor)->toBeNull();
});

it('reserves and unreserves an entry, and repoints the billed entries of a proforma to its invoice', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = lockRatedClient();
    $entry = lockClientEntry($admin, $client);
    $proforma = lockSystem(static fn (): string => lockDocument($client->id, ['kind' => 'proforma']));

    lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->reserve($rows, $proforma));
    expect(lockReload($entry)->invoice_id)->toBe($proforma);

    lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->unreserve($rows));
    expect(lockReload($entry)->invoice_id)->toBeNull();

    lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->reserve($rows, $proforma));
    lockSystem(static function () use ($proforma, $entry): void {
        lockIssue($proforma);
        lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->bill($rows, CarbonImmutable::parse('2026-10-17 10:30:00', 'UTC')));
    });
    $billed = lockReload($entry);

    $invoice = lockSystem(static function () use ($client, $proforma, $entry): string {
        $invoice = lockDocument($client->id, ['proforma_id' => $proforma]);
        lockIssue($invoice);
        lockRows([$entry], static fn (array $rows) => app(EntryBilling::class)->repoint($rows, $invoice));

        return $invoice;
    });
    $moved = lockReload($entry);

    expect($moved->invoice_id)->toBe($invoice)
        ->and($moved->billing_state->value)->toBe('billed')
        ->and($moved->billed_at?->equalTo($billed->billed_at))->toBeTrue()
        ->and($moved->billed_rate_minor)->toBe($billed->billed_rate_minor)
        ->and($moved->billed_amount_minor)->toBe($billed->billed_amount_minor);
});

it('refuses every writer method outside the Admin and system contexts', function (): void {
    $admin = Canary::admin();
    $client = lockRatedClient();
    $entry = lockClientEntry($admin, $client);
    $partner = Canary::partnerFor($client->id);
    $this->actingAs($partner);
    $billing = app(EntryBilling::class);
    $at = CarbonImmutable::parse('2026-10-17 10:30:00', 'UTC');

    expect(fn () => $billing->bill([$entry], $at))->toThrow(AuthorizationException::class)
        ->and(fn () => $billing->unbill([$entry]))->toThrow(AuthorizationException::class)
        ->and(fn () => $billing->reserve([$entry], $client->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $billing->unreserve([$entry]))->toThrow(AuthorizationException::class)
        ->and(fn () => $billing->release([$entry]))->toThrow(AuthorizationException::class)
        ->and(fn () => $billing->repoint([$entry], $client->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $billing->snapshot($entry))->toThrow(AuthorizationException::class)
        ->and(lockReload($entry)->billing_state->value)->toBe('unbilled');
});
