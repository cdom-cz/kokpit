<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;

/**
 * Holds a slot until the phase that owns the measurement replaces it (D-13):
 * rate date (Phase 8), webhooks (Phase 11), invoice e-mails (Phase 10).
 */
final readonly class PlaceholderIndicator implements HealthIndicator
{
    public function __construct(private HealthSlot $slot) {}

    public function slot(): HealthSlot
    {
        return $this->slot;
    }

    public function check(): HealthResult
    {
        return new HealthResult(HealthStatus::NotAvailable, null, __('kokpit.system.not_available_yet'));
    }
}
