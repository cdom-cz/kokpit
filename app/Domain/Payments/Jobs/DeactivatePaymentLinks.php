<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeGateway;
use App\Domain\Payments\Stripe\StripeGatewayFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Switches deactivated payment links off at Stripe (PA-04; D-08, research Pattern 3). Queued for one link right after
 * the transaction that deactivated it, and scheduled every five minutes without an id as the sweep that repairs any
 * call that was lost to an outage or a lost worker.
 *
 * The unit of work is a `payment_links` row in state deactivated that has a Stripe id and no `remote_deactivated_at`.
 * Stripe is called outside every transaction and lock; the stamp is then set under a short lock of the row, and only
 * when it is still empty. A Stripe failure is logged with its class and the row id and leaves the row for the next
 * sweep; nothing else, no provider text and no Stripe id, is written down.
 */
#[Idempotent(how: 'a row is done once remote_deactivated_at is set; setting active=false at Stripe twice is harmless')]
final class DeactivatePaymentLinks extends KokpitJob
{
    /** The most rows one run takes, so a long outage cannot make one run endless. */
    private const int BATCH = 50;

    /**
     * @param  string|null  $linkId  the row to switch off, or null for the sweep of every pending row
     */
    public function __construct(public readonly ?string $linkId = null) {}

    public function handle(StripeGateway $gateway): void
    {
        $query = PaymentLink::query()
            ->where('state', PaymentLinkState::Deactivated->value)
            ->whereNotNull('stripe_link_id')
            ->whereNull('remote_deactivated_at')
            ->orderBy('deactivated_at')
            ->orderBy('id')
            ->limit(self::BATCH);

        if ($this->linkId !== null) {
            // The id comes from the queue payload: a value that is no UUID names no row.
            if (! Str::isUuid($this->linkId)) {
                return;
            }

            $query->whereKey($this->linkId);
        }

        foreach ($query->get() as $link) {
            $this->deactivateLink($gateway, $link);
        }
    }

    private function deactivateLink(StripeGateway $gateway, PaymentLink $link): void
    {
        try {
            $gateway->deactivateLink((string) $link->stripe_link_id);
        } catch (StripeGatewayFailure $failure) {
            Log::warning('A payment link could not be deactivated at Stripe; the next sweep retries it.', [
                'payment_link_id' => $link->id,
                'failure_class' => $failure->failureClass,
            ]);

            return;
        }

        DB::transaction(static function () use ($link): void {
            $locked = PaymentLink::query()->whereKey($link->id)->whereNull('remote_deactivated_at')->lockForUpdate()->first();

            $locked?->forceFill(['remote_deactivated_at' => CarbonImmutable::now()])->save();
        });
    }
}
