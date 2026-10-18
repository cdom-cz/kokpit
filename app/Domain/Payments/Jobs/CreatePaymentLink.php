<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Operations\Alerts\AdminAlerter;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\PaymentLinkRequest;
use App\Domain\Payments\Stripe\StripeGateway;
use App\Domain\Payments\Stripe\StripeGatewayFailure;
use App\Domain\Payments\Support\StripeConfig;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\InvoiceResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

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
 *
 * A Stripe failure never fails the issue (D-09): the row becomes `failed` with a fixed failure class and every Admin
 * gets the bell notice and the e-mail, which name the document number only. A lost connection and a rate limit are
 * rethrown for the queue to retry with the same key until the last attempt; every other class is final at once,
 * because Stripe replays a stored 5xx answer for the same key (research Pitfall 7). The notice and the stored class
 * never hold a provider message, a Stripe id or a payer.
 */
#[Idempotent(how: 'identified by the payment link row id, which is also the idempotency key of the Stripe request; only a pending row is worked on, the Stripe call replays the same link for the same key, and the row moves out of pending in one locked transaction')]
final class CreatePaymentLink extends KokpitJob
{
    /** The failure class stored for anything that is not a Stripe failure. */
    public const string FAILURE_UNEXPECTED = 'unexpected';

    /** The number of attempts of the base class, used when the job runs without a queue job. */
    private const int DEFAULT_TRIES = 3;

    /** The same host rule as the CHECK constraint `payment_links_stripe_url_check`. */
    private const string STRIPE_URL_PATTERN = '#^https://([a-z0-9-]+\\.)*stripe\\.com/#';

    public function __construct(public readonly string $linkId) {}

    public function handle(StripeGateway $gateway, StripeConfig $config): void
    {
        // The id comes from the queue payload: a value that is no UUID names no row.
        if (! Str::isUuid($this->linkId)) {
            return;
        }

        $request = $this->prepare();

        if ($request === null) {
            return;
        }

        if (is_string($request)) {
            $this->markFailed($request);

            return;
        }

        if (! $config->isConfigured()) {
            $this->markFailed(StripeGatewayFailure::NOT_CONFIGURED);

            return;
        }

        try {
            $created = $gateway->createLink($request);
        } catch (StripeGatewayFailure $failure) {
            // The same key makes the retry safe; the last attempt gives the row its final state instead.
            if ($failure->isRetryable() && ! $this->isLastAttempt()) {
                throw $failure;
            }

            $this->markFailed($failure->failureClass);

            return;
        }

        if (preg_match(self::STRIPE_URL_PATTERN, $created->url) !== 1) {
            $this->markFailed(StripeGatewayFailure::API_ERROR, $created->id);

            return;
        }

        $this->settle($created->id, $created->url);
    }

    /**
     * The queue gave up. A row that is still pending becomes failed with the class of the failure, or `unexpected`,
     * and the Admins are told; a row that is already active, failed or deactivated is left alone. The job middleware
     * does not wrap this method, so the system context is entered here.
     */
    public function failed(Throwable $exception): void
    {
        if (! Str::isUuid($this->linkId)) {
            return;
        }

        app(PartnerContext::class)->runAsSystem(function () use ($exception): void {
            $this->markFailed($exception instanceof StripeGatewayFailure ? $exception->failureClass : self::FAILURE_UNEXPECTED);
        });
    }

    /**
     * Phase A: under the lock of the document and then of the row, the request for a link that is still pending.
     * Null when there is nothing to do; a failure class when the request cannot be built at all (a currency Stripe
     * does not take, ISK and UGX).
     */
    private function prepare(): PaymentLinkRequest|string|null
    {
        $invoiceId = PaymentLink::query()->whereKey($this->linkId)->value('invoice_id');

        if (! is_string($invoiceId)) {
            return null;
        }

        return DB::transaction(function () use ($invoiceId): PaymentLinkRequest|string|null {
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            $link = PaymentLink::query()->whereKey($this->linkId)->lockForUpdate()->first();

            if (! $invoice instanceof Invoice || ! $link instanceof PaymentLink || $link->state !== PaymentLinkState::Pending) {
                return null;
            }

            try {
                return $this->requestFor($invoice, $link);
            } catch (StripeGatewayFailure $failure) {
                return $failure->failureClass;
            } catch (InvalidArgumentException) {
                return StripeGatewayFailure::INVALID_REQUEST;
            }
        });
    }

    /**
     * Whether the queue will not try this job again after the current attempt.
     */
    private function isLastAttempt(): bool
    {
        return $this->attempts() >= ($this->job?->maxTries() ?? self::DEFAULT_TRIES);
    }

    /**
     * Moves a still pending row to failed with the fixed class, optionally keeping the Stripe id of a link that was
     * created but cannot be used, and then tells every Admin. The alert goes out after the transaction, once per
     * move; a row that is no longer pending is left alone.
     */
    private function markFailed(string $failureClass, ?string $stripeLinkId = null): void
    {
        $invoiceId = DB::transaction(function () use ($failureClass, $stripeLinkId): ?string {
            $link = PaymentLink::query()->whereKey($this->linkId)->lockForUpdate()->first();

            if (! $link instanceof PaymentLink || $link->state !== PaymentLinkState::Pending) {
                return null;
            }

            $link->forceFill([
                'state' => PaymentLinkState::Failed,
                'failure_class' => $failureClass,
                'stripe_link_id' => $stripeLinkId,
            ])->save();

            return $link->invoice_id;
        });

        if ($invoiceId !== null) {
            $this->alert($invoiceId);
        }
    }

    /**
     * The bell notice and the e-mail to every Admin (D-09, UI-SPEC Surface I): the document number and a fixed text,
     * one alert per document inside the throttle window. The alerter never throws.
     */
    private function alert(string $invoiceId): void
    {
        $number = Invoice::query()->whereKey($invoiceId)->value('number');
        $title = (string) __('payment_notices.link_failed.title');

        app(AdminAlerter::class)->alert('payment-link-failed:'.$invoiceId, new OperationalAlert(
            title: $title,
            body: (string) __('payment_notices.link_failed.body', ['number' => is_string($number) ? $number : '']),
            actionUrl: InvoiceResource::getUrl('view', ['record' => $invoiceId]),
            actionLabel: (string) __('payment_notices.link_failed.action'),
            mailSubject: (string) __('payment_notices.mail_subject', ['title' => $title]),
        ));
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
