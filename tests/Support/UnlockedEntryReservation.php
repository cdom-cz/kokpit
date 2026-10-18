<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\EntryReservation;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Test-only mutation for the reservation concurrency harness; never used by application code.
 *
 * Identical to EntryReservation::claim() except that the candidates are read without FOR UPDATE and every one of
 * them is reserved, without judging the rows under a lock. Two drafts created at the same moment can then both
 * decide that the same entries are free, so the harness must see a link-guard refusal or seconds counted twice. If it
 * does not, a clean run against the real claim proves nothing.
 *
 * A short sleep between the read and the reservation widens the race window; the production class gets no such hook.
 */
final readonly class UnlockedEntryReservation extends EntryReservation
{
    public function __construct(private EntryBilling $entryBilling)
    {
        parent::__construct($entryBilling);
    }

    /**
     * @param  list<string>  $candidateIds
     * @return Collection<int, TimeEntry>
     */
    #[\Override]
    public function claim(Invoice $lockedDraft, array $candidateIds): Collection
    {
        if ($candidateIds === []) {
            return new Collection;
        }

        // The only difference to the real claim: no lock and no judgement on locked rows.
        $read = TimeEntry::query()->whereIn('id', $candidateIds)->orderBy('id')->get();

        DB::select('select pg_sleep(0.05)');

        $this->entryBilling->reserve($read, $lockedDraft->id);

        return $read->values();
    }
}
