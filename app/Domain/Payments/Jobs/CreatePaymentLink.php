<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\PaymentLinkRequest;
use App\Domain\Payments\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the single-use Stripe payment link of one issued invoice or proforma (PA-01; D-05 to D-09, research
 * Pattern 2). The unit is the `payment_links` row the issue opened pending; the job only moves that row on.
 *
 * Stripe is never called inside a transaction or while the document row is locked: the job locks the document and the
 * row, checks that the link is still wanted and builds the request, commits, calls Stripe with the id of the row as
 * idempotency key, then locks both again and decides on the new state of the world.
 *
 * Idempotence: the row id is the key of the Stripe request, so a retry of the same attempt gets the link Stripe
 * already created, and a row that is no longer pending is done.
 */
#[Idempotent(how: 'identified by the payment link row id, which is also the idempotency key of the Stripe request; only a pending row is worked on, the Stripe call replays the same link for the same key, and the row moves out of pending in one locked transaction')]
final class CreatePaymentLink extends KokpitJob
{
    public function __construct(public readonly string $linkId) {}

    public function handle(StripeGateway $gateway): void
    {
        // The id comes from the queue payload: a value that is no UUID names no row.
        if (! Str::isUuid($this->linkId)) {
            return;
        }

        $request = $this->prepare();

        if ($request === null) {
            return;
        }

        $created = $gateway->createLink($request);

        $this->settle($created->id, $created->url);
    }

    /**
     * Phase A: under the lock of the document and then of the row, the request for a link that is still pending.
     * Null when there is nothing to do.
     */
    private function prepare(): ?PaymentLinkRequest
    {
        $invoiceId = PaymentLink::query()->whereKey($this->linkId)->value('invoice_id');

        if (! is_string($invoiceId)) {
            return null;
        }

        return DB::transaction(function () use ($invoiceId): ?PaymentLinkRequest {
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            $link = PaymentLink::query()->whereKey($this->linkId)->lockForUpdate()->first();

            if (! $invoice instanceof Invoice || ! $link instanceof PaymentLink || $link->state !== PaymentLinkState::Pending) {
                return null;
            }

            return $this->requestFor($invoice, $link);
        });
    }

    /**
     * Phase C: under the lock of the document and then of the row, the link becomes active.
     */
    private function settle(string $stripeLinkId, string $stripeUrl): void
    {
        $invoiceId = PaymentLink::query()->whereKey($this->linkId)->value('invoice_id');

        if (! is_string($invoiceId)) {
            return;
        }

        DB::transaction(function () use ($invoiceId, $stripeLinkId, $stripeUrl): void {
            Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $link = PaymentLink::query()->whereKey($this->linkId)->lockForUpdate()->firstOrFail();

            if ($link->state !== PaymentLinkState::Pending) {
                return;
            }

            $link->forceFill([
                'state' => PaymentLinkState::Active,
                'stripe_link_id' => $stripeLinkId,
                'stripe_url' => $stripeUrl,
                'activated_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    /**
     * The Stripe request for the amount the row offers, with the texts in the language of the document (U-15).
     */
    private function requestFor(Invoice $invoice, PaymentLink $link): PaymentLinkRequest
    {
        $language = $invoice->language->value;
        $number = (string) $invoice->number;
        $product = $invoice->kind === InvoiceKind::Proforma ? 'proforma' : 'invoice';

        return new PaymentLinkRequest(
            idempotencyKey: $link->id,
            amountMinor: $link->amount_minor,
            currency: $link->currency,
            productName: (string) trans('payment_links.stripe.product.'.$product, ['number' => $number], $language),
            invoiceId: $invoice->id,
            linkRowId: $link->id,
            confirmationMessage: (string) trans('payment_links.stripe.confirmation', ['number' => $number], $language),
            inactiveMessage: (string) trans('payment_links.stripe.inactive', ['number' => $number], $language),
        );
    }
}
