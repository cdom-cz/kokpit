<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Stripe events that were stored but not processed (PA-05, PA-08; D-17, UI-SPEC Surface K).
 *
 * One aggregate over the stored Stripe events with `processed_at` empty: how many there are, how many of them failed
 * for good (`failed_at` set) and the creation time of the oldest. Any failed event is Error; otherwise an event that
 * waits longer than kokpit.health.unprocessed_webhook_warning_after is Warning; otherwise the slot is Ok, with the
 * waiting text when something waits. The value is the count of unprocessed events.
 *
 * The indicator reads counts and a timestamp only. It never selects a payload, a header, an event id or the failure
 * class, so none of them can reach the System page (T-11-51); the detail is fixed Czech copy with a count and an age.
 * The slot offers no retry control (U-13): the failed job list is where the Admin reruns a job.
 */
final readonly class UnprocessedWebhooksIndicator implements HealthIndicator
{
    public function slot(): HealthSlot
    {
        return HealthSlot::UnprocessedWebhooks;
    }

    public function check(): HealthResult
    {
        $now = CarbonImmutable::now();
        $warningAfter = max(1, (int) config('kokpit.health.unprocessed_webhook_warning_after'));

        // The model is Admin-only and the registry may also run outside a request, so the read is explicit.
        $row = app(PartnerContext::class)->runAsSystem(
            static fn (): object => (object) WebhookCall::query()
                ->where('name', 'stripe')
                ->whereNull('processed_at')
                ->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE failed_at IS NOT NULL) AS failed, MIN(created_at) AS oldest')
                ->toBase()
                ->first(),
        );

        $total = (int) $row->total;

        if ($total === 0 || $row->oldest === null) {
            return new HealthResult(HealthStatus::Ok, '0', __('kokpit.system.webhooks.ok'));
        }

        $oldest = CarbonImmutable::parse((string) $row->oldest);
        $age = $oldest->diffForHumans($now, CarbonInterface::DIFF_ABSOLUTE);
        $replace = ['count' => $total, 'age' => $age];

        if ((int) $row->failed > 0) {
            return new HealthResult(HealthStatus::Error, (string) $total, __('kokpit.system.webhooks.error', $replace));
        }

        $status = $oldest->lessThanOrEqualTo($now->subSeconds($warningAfter)) ? HealthStatus::Warning : HealthStatus::Ok;

        return new HealthResult($status, (string) $total, __('kokpit.system.webhooks.waiting', $replace));
    }
}
