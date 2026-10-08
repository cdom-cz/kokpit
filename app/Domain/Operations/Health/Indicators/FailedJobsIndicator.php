<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use Illuminate\Queue\Failed\CountableFailedJobProvider;

/**
 * Counts the jobs that failed for good (D-12), through the queue failer and not
 * through the table, so the storage driver stays the framework's business.
 *
 * Warning from kokpit.health.failed_jobs_warning_at failed jobs on. A failer
 * that cannot count (for example the null driver) is NotAvailable, never Ok.
 */
final readonly class FailedJobsIndicator implements HealthIndicator
{
    public function slot(): HealthSlot
    {
        return HealthSlot::FailedJobs;
    }

    public function check(): HealthResult
    {
        // Typed loosely on purpose: the configured driver decides whether the failer can count.
        /** @var object $failer */
        $failer = app('queue.failer');

        if (! $failer instanceof CountableFailedJobProvider) {
            return new HealthResult(HealthStatus::NotAvailable, null, __('kokpit.system.failed_jobs.not_countable'));
        }

        $count = $failer->count();
        $warningAt = max(1, (int) config('kokpit.health.failed_jobs_warning_at'));

        if ($count >= $warningAt) {
            return new HealthResult(HealthStatus::Warning, (string) $count, __('kokpit.system.failed_jobs.some'));
        }

        return new HealthResult(HealthStatus::Ok, (string) $count, __('kokpit.system.failed_jobs.none'));
    }
}
