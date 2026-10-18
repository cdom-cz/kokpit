<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

/**
 * The part of Stripe's answer to a payment link creation that Kokpit keeps: the provider id of the link and the
 * hosted address the payer opens.
 */
final readonly class CreatedPaymentLink
{
    public function __construct(
        public string $id,
        public string $url,
    ) {}
}
