<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PaymentLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Admin switches an active payment link off by hand (PA-04; UI-SPEC Surface E). The document is untouched and the
 * link can be regenerated afterwards ({@see RegeneratePaymentLink}).
 *
 * One transaction: the document is locked first, then its link. The active link is deactivated with the reason
 * `manual` and then at Stripe after the commit. A document without an active link (none, still being prepared, or
 * failed) is refused under `payment_link` with `no_active_link`. The Admin only.
 */
final readonly class DeactivatePaymentLink
{
    public function __construct(private PaymentLinks $links) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Invoice $invoice): void
    {
        Gate::forUser($actor)->authorize('update', $invoice);

        DB::transaction(function () use ($invoice): void {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $link = PaymentLink::query()
                ->where('invoice_id', $locked->id)
                ->where('state', PaymentLinkState::Active->value)
                ->lockForUpdate()
                ->first();

            if (! $link instanceof PaymentLink) {
                throw ValidationException::withMessages(['payment_link' => __('payment_links.errors.no_active_link')]);
            }

            $this->links->deactivate($link, PaymentLinkDeactivation::Manual);
        });
    }
}
