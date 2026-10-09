<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\CancelEntriesBilling;
use App\Domain\TimeTracking\Actions\DeleteTimeEntry;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Enums\BillingBadge;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use App\Filament\Resources\TimeEntryResource\Pages\EditTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use Carbon\CarbonImmutable;
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
