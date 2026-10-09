<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\CancelEntriesBilling;
use App\Domain\TimeTracking\Actions\DeleteTimeEntry;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
