<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

/**
 * The thin seam between Kokpit and Stripe (D-18): every Stripe call of the application goes through these two
 * methods, so the test suite binds a fake and never reaches the network, and a replacement of the SDK touches
 * only app/Domain/Payments/Stripe/. Both methods throw StripeGatewayFailure and nothing else.
 */
interface StripeGateway
{
    /**
     * Creates a single-use payment link for an exact amount (PA-01). The idempotency key of the request makes
     * a repeated call for the same Kokpit link row return the link Stripe already created.
     *
     * @throws StripeGatewayFailure
     */
    public function createLink(PaymentLinkRequest $request): CreatedPaymentLink;

    /**
     * Deactivates a payment link so it can no longer be paid (PA-04). Deactivating a link that is already
     * inactive succeeds again.
     *
     * @throws StripeGatewayFailure
     */
    public function deactivateLink(string $stripeLinkId): void;
}
