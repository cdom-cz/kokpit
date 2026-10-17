<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * The claim of time entries by a draft (IN-02, D-04; research Pattern 2, Pitfall 1): the candidates are locked FOR
 * UPDATE in id order, eligibility is decided on the locked rows and only the rows that are still free are reserved.
 *
 * A list of candidates read without a lock is only a proposal: another draft may have taken an entry, a timer may
 * have been edited or the entry billed by hand since. A select followed by an update would reserve on stale
 * knowledge, so the decision is made here, on the rows this transaction holds. The locking order is the id, so two
 * claims of overlapping entries wait for each other and never deadlock; the later one finds the first one's link on
 * the freshly locked row and leaves that entry out. {@see EntryBilling::reserve()} writes each row as a model, and the
 * link guard of the database backs the rule for every other writer.
 *
 * Must run inside the transaction that holds the lock on the draft.
 */
final readonly class EntryReservation
{
    public function __construct(private EntryBilling $billing) {}

    /**
     * Locks the candidates and reserves those still free for the draft.
     *
     * @param  Invoice  $lockedDraft  the draft, locked in the running transaction
     * @param  list<string>  $candidateIds
     * @return Collection<int, TimeEntry> the entries now held by the draft, with the values read under the lock
     */
    public function claim(Invoice $lockedDraft, array $candidateIds): Collection
    {
        if ($candidateIds === []) {
            return new Collection;
        }

        $locked = TimeEntry::query()->whereIn('id', $candidateIds)->orderBy('id')->lockForUpdate()->get();

        $free = $locked->filter(static fn (TimeEntry $entry): bool => $entry->client_id === $lockedDraft->client_id
            && $entry->billing_state === BillingState::Unbilled
            && $entry->invoice_id === null
            && $entry->billable
            && $entry->ended_at !== null)->values();

        $this->billing->reserve($free, $lockedDraft->id);

        return $free;
    }
}
