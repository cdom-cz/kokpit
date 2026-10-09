<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Domain\TimeTracking\TimeEntryInput;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Marks a selection of time entries as billed (TI-05, D-06).
 *
 * An entry is eligible when it is finished, billable and not yet billed. A
 * running, a non-billable and an already billed entry are skipped, never an
 * error, and counted by reason (UI-SPEC U-10); a skipped entry is counted once,
 * under the first reason that applies (billed, running, non-billable). A
 * zero-length finished billable entry is eligible. The ids come from the
 * browser and may be stale or forged: a malformed id is dropped, an unknown id
 * matches nothing, and an id billed by another request after the page loaded
 * counts as already billed.
 *
 * One transaction: the rows are read FOR UPDATE in id order, so two selections
 * that overlap lock in the same order and cannot deadlock, and the eligibility
 * is decided on the locked rows, never on the page the Admin saw. Each eligible
 * row is saved as a model, one by one, because a bulk query update raises no
 * model event and would write no activity row: every entry writes exactly one
 * allowlisted history row (the description is never part of it). The database
 * (`time_entries_billed_finished_check`) refuses billing a running or
 * non-billable entry for any writer.
 *
 * `preview()` computes the same buckets without a lock and without a write, for
 * the confirmation modal, so the modal and the write cannot disagree.
 *
 * The billed state is the one Phase 10 invoicing reuses; the unlock is
 * CancelEntriesBilling only.
 */
final class MarkEntriesBilled
{
    /**
     * @param  array<array-key, mixed>  $ids
     * @return array{billed: int, billed_seconds: int, skipped_non_billable: int, skipped_billed: int, skipped_running: int}
     *
     * @throws DomainException nothing in the selection is eligible
     */
    public function handle(User $actor, array $ids): array
    {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);

        $ids = TimeEntryInput::uuids($ids);

        return DB::transaction(function () use ($actor, $ids): array {
            $rows = $this->selection($ids, lock: true);

            foreach ($rows as $row) {
                Gate::forUser($actor)->authorize('update', $row);
            }

            $buckets = $this->buckets($rows);

            if ($buckets['eligible'] === []) {
                throw new DomainException(__('kokpit.time.errors.nothing_to_bill'));
            }

            $now = TimerClock::now();
            $seconds = 0;

            foreach ($buckets['eligible'] as $row) {
                $row->forceFill(['billing_state' => BillingState::Billed, 'billed_at' => $now])->save();

                $seconds += (int) $row->duration_seconds;
            }

            return [
                'billed' => count($buckets['eligible']),
                'billed_seconds' => $seconds,
                'skipped_non_billable' => $buckets['non_billable'],
                'skipped_billed' => $buckets['billed'],
                'skipped_running' => $buckets['running'],
            ];
        });
    }

    /**
     * What a run on the same selection would do right now; nothing is locked or written.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array{eligible: int, eligible_seconds: int, skipped: int}
     */
    public function preview(array $ids): array
    {
        $buckets = $this->buckets($this->selection(TimeEntryInput::uuids($ids), lock: false));

        return [
            'eligible' => count($buckets['eligible']),
            'eligible_seconds' => $this->seconds($buckets['eligible']),
            'skipped' => $buckets['non_billable'] + $buckets['billed'] + $buckets['running'],
        ];
    }

    /**
     * The rows of the selection, ordered by id, optionally locked.
     *
     * @param  list<string>  $ids
     * @return Collection<int, TimeEntry>
     */
    private function selection(array $ids, bool $lock): Collection
    {
        $query = TimeEntry::query()->whereKey($ids)->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    /**
     * The single eligibility rule: eligible rows and the skipped counts by reason.
     *
     * @param  Collection<int, TimeEntry>  $rows
     * @return array{eligible: list<TimeEntry>, non_billable: int, billed: int, running: int}
     */
    private function buckets(Collection $rows): array
    {
        $buckets = ['eligible' => [], 'non_billable' => 0, 'billed' => 0, 'running' => 0];

        foreach ($rows as $row) {
            match (true) {
                $row->billing_state === BillingState::Billed => $buckets['billed']++,
                $row->isRunning() => $buckets['running']++,
                ! $row->billable => $buckets['non_billable']++,
                default => $buckets['eligible'][] = $row,
            };
        }

        return $buckets;
    }

    /**
     * @param  list<TimeEntry>  $rows
     */
    private function seconds(array $rows): int
    {
        return array_sum(array_map(static fn (TimeEntry $row): int => (int) $row->duration_seconds, $rows));
    }
}
