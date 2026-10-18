<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Throws a draft away (IN-10, D-05): every time entry it reserved becomes free again, then the line sources, the
 * lines, the Admin-only row and the draft itself are deleted in one transaction. The Admin only.
 *
 * No number is consumed, because a draft has none. An issued document can never be deleted: this Action refuses it
 * on the locked row, and the database guard would refuse it as well (KP001), so a stale page or a raw call fails
 * the same way. The entries are released with {@see EntryBilling::unreserve()}, which writes each row as a model, so
 * every release leaves its own history row; the entries are locked in id order before the draft's own rows go.
 */
final readonly class DeleteDraft
{
    public function __construct(private EntryBilling $billing) {}

    /**
     * @throws DomainException when the document is no longer a draft
     */
    public function handle(User $actor, Invoice $draft): void
    {
        Gate::forUser($actor)->authorize('delete', $draft);

        DB::transaction(function () use ($draft): void {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            $entries = TimeEntry::query()->where('invoice_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $this->billing->unreserve($entries);

            InvoiceItemSource::query()->where('invoice_id', $locked->id)->delete();
            InvoiceItem::query()->where('invoice_id', $locked->id)->delete();

            foreach (InvoiceAdmin::query()->where('invoice_id', $locked->id)->get() as $admin) {
                $admin->delete();
            }

            $locked->delete();
        });
    }
}
