<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\TimeTracking\Billing\EntryBilling;
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
 * Time billed by an issued invoice (a billed entry with an `invoice_id`) is not unlocked here: it is released only
 * by a full storno credit note (D-14). A selection that contains such an entry is refused as a whole (ruling
 * R-09-3, all or nothing, so a partial unlock cannot hide which entries stayed locked) with a message naming
 * the first invoice by id order, and nothing changes; the link guard of the database is the backstop. `preview()`
 * then counts nothing, because a run would unlock nothing.
 *
 * Same shape as MarkEntriesBilled: one transaction, rows FOR UPDATE in id order,
 * eligibility decided on the locked rows, one model save per entry so each
 * writes one allowlisted history row. `preview()` is the read-only count for the
 * confirmation modal.
 */
final class CancelEntriesBilling
{
    public function __construct(private readonly EntryBilling $billing) {}

    /**
     * @param  array<array-key, mixed>  $ids
     * @return int the number of entries unlocked
     *
     * @throws DomainException nothing in the selection is billed, or an entry is billed by an issued invoice
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

            $this->refuseInvoiceBilled($billed);

            $this->billing->unbill($billed);

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

        if ($this->invoiceIds($billed) !== []) {
            $billed = [];
        }

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

    /**
     * Refuses the whole selection when one of its billed rows belongs to an invoice, naming the first invoice by id.
     *
     * @param  list<TimeEntry>  $billed
     *
     * @throws DomainException
     */
    private function refuseInvoiceBilled(array $billed): void
    {
        $invoiceIds = $this->invoiceIds($billed);

        if ($invoiceIds === []) {
            return;
        }

        $number = Invoice::query()->whereKey($invoiceIds[0])->value('number');

        throw new DomainException(__('kokpit.time.errors.invoice_locked', ['number' => is_string($number) ? $number : '']));
    }

    /**
     * The distinct invoice ids of the billed rows, in id order.
     *
     * @param  list<TimeEntry>  $billed
     * @return list<string>
     */
    private function invoiceIds(array $billed): array
    {
        $ids = [];

        foreach ($billed as $row) {
            if ($row->invoice_id !== null) {
                $ids[$row->invoice_id] = $row->invoice_id;
            }
        }

        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }
}
