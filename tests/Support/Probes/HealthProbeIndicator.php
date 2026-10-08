<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use Throwable;

/**
 * A test-only indicator that counts its check() calls and either answers with a
 * fixed result or throws. It is how a test proves that an indicator did not run.
 */
final class HealthProbeIndicator implements HealthIndicator
{
    public int $checks = 0;

    public function __construct(
        private readonly HealthSlot $slot,
        private readonly HealthResult|Throwable $outcome = new HealthResult(HealthStatus::Ok, 'probe'),
    ) {}

    public function slot(): HealthSlot
    {
        return $this->slot;
    }

    public function check(): HealthResult
    {
        $this->checks++;

        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
