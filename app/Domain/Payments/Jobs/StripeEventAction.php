<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Stripe\StripeEvent;

/**
 * Work for one type of Stripe event, written against the parsed event instead of the stored row (R11-13-5).
 *
 * The handler map of {@see ProcessStripeWebhook} accepts both this and {@see StripeEventHandler}: the older interface
 * hands the whole stored row to its handler, this one hands the event already read defensively by
 * {@see StripeEvent::fromPayload()}. An action is called inside the transaction that holds the lock on the event row,
 * in the system context, and returns the record it wrote, or null when it recorded nothing.
 */
interface StripeEventAction
{
    public function handle(StripeEvent $event): ?CardPayment;
}
