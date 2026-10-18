<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Shared\Models\WebhookCall;

/**
 * Work for one type of stored Stripe event (R11-04-6).
 *
 * ProcessStripeWebhook calls it inside the transaction that holds the lock on the event row, in the system context,
 * and marks the event processed when it returns. A handler throws to leave the event unprocessed; the job is then
 * retried, and after the last try the event is marked failed.
 */
interface StripeEventHandler
{
    public function handle(WebhookCall $call): void;
}
