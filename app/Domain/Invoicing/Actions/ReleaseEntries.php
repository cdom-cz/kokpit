<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoiceAudit;
use App\Domain\Invoicing\Support\IssuedDocumentRules;
use App\Domain\Invoicing\Support\IssuedEditFinisher;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unlinks selected time entries from a draft or from an open issued document, "Odpojit od faktury" (IN-10, IN-13, D-05,
 * D-07). The Admin only. The name stays; the lifecycle is decided on the locked document (R-10.1-09-1): a draft follows
 * the draft path, an issued document asks {@see IssuedDocumentRules::assertEditable()} and follows the issued path,
 * anything else keeps the `not_draft` refusal.
 *
 * The items of the document stay exactly as they are, because the lines are the Admin's to edit or delete (D-03);
 * nothing here deletes or rewrites an item, and the totals do not move until the Admin edits the lines.
 *
 * Draft: in one transaction the draft is locked, then the requested entries that are linked to this very draft are
 * locked in id order and freed through {@see EntryBilling::unreserve()}.
 *
 * Issued (R-10.1-09-3): the requested entries billed by this document are locked in id order and freed through
 * {@see EntryBilling::release()}: they return to unbilled with no link and no snapshot. The database link guard allows
 * it only while the document is open and refuses it once the document is locked, a credit note, cancelled or a
 * converted proforma. The path ends with {@see IssuedEditFinisher::finish()} (the card link, the PDF and, after the
 * first send, an audit row whose `entries` value went down, T-10.1-34).
 *
 * An id of an entry of another document, of a free entry or of nothing at all is ignored, not an error: the count tells
 * the caller how many were released.
 */
final readonly class ReleaseEntries
{
    public function __construct(
        private EntryBilling $billing,
        private IssuedEditFinisher $finisher,
    ) {}

    /**
     * @param  array<array-key, mixed>  $entryIds
     * @return int the number of entries released
     *
     * @throws ValidationException `document` when an issued document cannot release time
     * @throws DomainException when the document is neither a draft nor an issued document
     */
    public function handle(User $actor, Invoice $draft, array $entryIds): int
    {
        Gate::forUser($actor)->authorize('update', $draft);

        $ids = array_values(array_unique(array_filter($entryIds, static fn (mixed $id): bool => is_string($id) && Str::isUuid($id))));

        return DB::transaction(function () use ($actor, $draft, $ids): int {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isIssued()) {
                IssuedDocumentRules::assertEditable($locked);
            } elseif (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            if ($ids === []) {
                return 0;
            }

            return $locked->isIssued() ? $this->releaseFromIssued($actor, $locked, $ids) : $this->unreserveFromDraft($locked, $ids);
        });
    }

    /**
     * @param  list<string>  $ids
     */
    private function unreserveFromDraft(Invoice $locked, array $ids): int
    {
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
    }

    /**
     * @param  list<string>  $ids
     */
    private function releaseFromIssued(User $actor, Invoice $locked, array $ids): int
    {
        $before = InvoiceAudit::snapshot($locked);

        $held = TimeEntry::query()
            ->whereIn('id', $ids)
            ->where('invoice_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($held->isEmpty()) {
            return 0;
        }

        $this->billing->release($held);

        $this->finisher->finish($actor, $locked, $before);

        return $held->count();
    }
}
