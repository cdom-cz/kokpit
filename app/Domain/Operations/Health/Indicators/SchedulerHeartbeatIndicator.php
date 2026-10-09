<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Heartbeats;

/**
 * Whether the scheduler is alive (D-12): Error when its heartbeat is older than
 * kokpit.health.scheduler_heartbeat_error_after seconds, and also when no
 * heartbeat was ever recorded. A scheduler that never ran is not healthy.
 */
final readonly class SchedulerHeartbeatIndicator implements HealthIndicator
{
    public function slot(): HealthSlot
    {
        return HealthSlot::SchedulerHeartbeat;
    }

    public function check(): HealthResult
    {
        $recordedAt = app(Heartbeats::class)->scheduler();

        if ($recordedAt === null) {
            return new HealthResult(HealthStatus::Error, null, __('kokpit.system.scheduler.never'));
        }

        $age = max(0, now()->getTimestamp() - $recordedAt);
        $errorAfter = (int) config('kokpit.health.scheduler_heartbeat_error_after');

        if ($age > $errorAfter) {
            return new HealthResult(HealthStatus::Error, $age.' s', __('kokpit.system.scheduler.late'));
        }

        return new HealthResult(HealthStatus::Ok, $age.' s', __('kokpit.system.scheduler.alive'));
    }
}
