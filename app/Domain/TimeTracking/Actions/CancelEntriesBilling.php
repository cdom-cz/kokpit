<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cancels the billing of a selection of time entries (TI-05, D-06): the only way
 * back from billed to unbilled.
 *
 * Every billed entry of the selection returns to `unbilled` with `billed_at`
 * null; anything else in the selection (unbilled, unknown, malformed ids) is
 * ignored. The guard trigger of the table allows exactly this flip of the two
 * billing columns on a billed row, and nothing else. A selection with nothing
 * billed raises the DomainException `kokpit.time.errors.nothing_to_unbill` and
 * changes nothing.
 *
 * Same shape as MarkEntriesBilled: one transaction, rows FOR UPDATE in id order,
 * eligibility decided on the locked rows, one model save per entry so each
 * writes one allowlisted history row. `preview()` is the read-only count for the
 * confirmation modal.
 */
final class CancelEntriesBilling
{
    /**
     * @param  array<array-key, mixed>  $ids
     * @return int the number of entries unlocked
     *
     * @throws DomainException nothing in the selection is billed
     */
    public function handle(User $actor, array $ids): int
    {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);

        $ids = TimeEntryInput::uuids($ids);

        return DB::transaction(function () use ($actor, $ids): int {
            $billed = $this->billed($this->selection($ids, lock: true));

            foreach ($billed as $row) {
                Gate::forUser($actor)->authorize('update', $row);
            }

            if ($billed === []) {
                throw new DomainException(__('kokpit.time.errors.nothing_to_unbill'));
            }

            foreach ($billed as $row) {
                $row->forceFill(['billing_state' => BillingState::Unbilled, 'billed_at' => null])->save();
            }

            return count($billed);
        });
    }

    /**
     * What a run on the same selection would unlock right now; nothing is locked or written.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array{eligible: int, eligible_seconds: int}
     */
    public function preview(array $ids): array
    {
        $billed = $this->billed($this->selection(TimeEntryInput::uuids($ids), lock: false));

        return [
            'eligible' => count($billed),
            'eligible_seconds' => array_sum(array_map(static fn (TimeEntry $row): int => (int) $row->duration_seconds, $billed)),
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
     * The single eligibility rule: the billed rows.
     *
     * @param  Collection<int, TimeEntry>  $rows
     * @return list<TimeEntry>
     */
    private function billed(Collection $rows): array
    {
        return array_values($rows->filter(static fn (TimeEntry $row): bool => $row->billing_state === BillingState::Billed)->all());
    }
}
