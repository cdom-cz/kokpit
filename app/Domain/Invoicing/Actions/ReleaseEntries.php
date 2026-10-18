<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Unlinks selected time entries from a draft, "Odpojit od faktury" (IN-10, D-05): they return to unbilled and
 * unreserved at once and can be picked by another draft. The items of the draft stay exactly as they are, because
 * the lines are the Admin's to edit or delete (D-03); nothing here deletes or rewrites an item. The Admin only.
 *
 * Only a draft lets go of time. The entries of an issued document are billed and locked, and the database link
 * guard refuses to release them without the full storno of plan 10-23 (D-14); this Action does not touch them,
 * and a draft that has been issued meanwhile is refused with `not_draft`.
 *
 * In one transaction the draft is locked, then the requested entries that are linked to this very draft are locked
 * in id order and freed through {@see EntryBilling::unreserve()}. An id of an entry of another draft, of a free
 * entry or of nothing at all is ignored, not an error: the count tells the caller how many were released.
 */
final readonly class ReleaseEntries
{
    public function __construct(private EntryBilling $billing) {}

    /**
     * @param  array<array-key, mixed>  $entryIds
     * @return int the number of entries released
     *
     * @throws DomainException when the document is no longer a draft
     */
    public function handle(User $actor, Invoice $draft, array $entryIds): int
    {
        Gate::forUser($actor)->authorize('update', $draft);

        $ids = array_values(array_unique(array_filter($entryIds, static fn (mixed $id): bool => is_string($id) && Str::isUuid($id))));

        return DB::transaction(function () use ($draft, $ids): int {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            if ($ids === []) {
                return 0;
            }

            $held = TimeEntry::query()
                ->whereIn('id', $ids)
                ->where('invoice_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(static fn (TimeEntry $entry): bool => $entry->billing_state === BillingState::Unbilled)
                ->values();

            $this->billing->unreserve($held);

            return $held->count();
        });
    }
}
