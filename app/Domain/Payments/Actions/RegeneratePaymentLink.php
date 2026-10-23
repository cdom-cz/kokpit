<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PaymentLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Admin offers card payment again for what a document still owes (PA-04; D-07, D-08, UI-SPEC Surface E).
 *
 * One transaction: the document is locked first, then its live link. The old link, when it is active, is deactivated
 * first with the reason `replaced` (and then at Stripe after the commit), so at no time two links are live; a new
 * pending link for the remaining amount is opened and created by the same idempotent job as at issue.
 *
 * Refused under `payment_link`: `not_regenerable` for a document that is not an issued, uncancelled invoice or
 * proforma with its card switch on and an amount still owed, and `preparing` while the live link is still
 * being created. The Admin only.
 */
final readonly class RegeneratePaymentLink
{
    public function __construct(
        private PaymentLinks $links,
        private InvoiceBalances $balances,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Invoice $invoice): PaymentLink
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        $link = DB::transaction(function () use ($actor, $invoice): PaymentLink {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $remaining = $this->remaining($locked);

            if ($remaining === null) {
                throw ValidationException::withMessages(['payment_link' => __('payment_links.errors.not_regenerable')]);
            }

            $live = PaymentLink::query()->live()->where('invoice_id', $locked->id)->lockForUpdate()->first();

            if ($live instanceof PaymentLink && $live->state === PaymentLinkState::Pending) {
                throw ValidationException::withMessages(['payment_link' => __('payment_links.errors.preparing')]);
            }

            if ($live instanceof PaymentLink) {
                $this->links->deactivate($live, PaymentLinkDeactivation::Replaced);
            }

            $new = PaymentLink::open($locked, $remaining, $actor);

            CreatePaymentLink::dispatch($new->id)->afterCommit();

            return $new;
        });

        return $link;
    }

    /**
     * What the document still owes when it can be offered card payment, otherwise null.
     */
    private function remaining(Invoice $locked): ?int
    {
        if ($locked->state !== InvoiceState::Issued || ! $locked->kind->carriesPayment() || $locked->isCancelled()) {
            return null;
        }

        $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->first();

        if (! $admin instanceof InvoiceAdmin || ! $admin->card_payment_enabled) {
            return null;
        }

        $remaining = $this->balances->for([$locked->id])[$locked->id]['remaining'] ?? 0;

        return $remaining > 0 ? $remaining : null;
    }
}
