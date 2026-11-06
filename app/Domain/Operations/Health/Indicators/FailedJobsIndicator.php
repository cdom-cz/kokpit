<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Laravel\Horizon\Contracts\JobRepository;

/**
 * Counts the jobs that failed for good (D-12), from the store the queue actually uses.
 *
 * When Horizon's JobRepository is bound and the default queue connection uses the redis
 * driver, Horizon is the failure store: the count is countFailed(), the set behind
 * Horizon's Failed Jobs list, counted only within horizon.trim.failed minutes. It is not
 * countRecentlyFailed(), because horizon:forget clears an entry from the failed set (and
 * --all also forgets the failer row) but never from the recent set, so a reviewed failure
 * could not be acknowledged and would keep the Warning for up to a week. A job retried
 * from Horizon stays in the failed set until it is forgotten or trimmed.
 *
 * On every other connection the queue failer counts, and not the table, so the storage
 * driver stays the framework's business. A failer that cannot count (for example the null
 * driver) is NotAvailable, never Ok. A Redis error on the Horizon branch reaches the
 * registry, which shows Error with the exception class only.
 *
 * Warning from kokpit.health.failed_jobs_warning_at failed jobs on, for both sources.
 */
final readonly class FailedJobsIndicator implements HealthIndicator
{
    public function slot(): HealthSlot
    {
        return HealthSlot::FailedJobs;
    }

    public function check(): HealthResult
    {
        $horizon = $this->horizonRepository();

        if ($horizon instanceof JobRepository) {
            $count = $horizon->countFailed();
        } else {
            // Typed loosely on purpose: the configured driver decides whether the failer can count.
            /** @var object $failer */
            $failer = app('queue.failer');

            if (! $failer instanceof CountableFailedJobProvider) {
                return new HealthResult(HealthStatus::NotAvailable, null, __('kokpit.system.failed_jobs.not_countable'));
            }

            $count = $failer->count();
        }

        $warningAt = max(1, (int) config('kokpit.health.failed_jobs_warning_at'));

        if ($count >= $warningAt) {
            return new HealthResult(HealthStatus::Warning, (string) $count, __('kokpit.system.failed_jobs.some'));
        }

        return new HealthResult(HealthStatus::Ok, (string) $count, __('kokpit.system.failed_jobs.none'));
    }

    /**
     * Horizon's job repository, or null when the failer is the source. Decided on the configured
     * driver of the default connection, so it never builds a queue connection.
     */
    private function horizonRepository(): ?JobRepository
    {
        if (! app()->bound(JobRepository::class)) {
            return null;
        }

        $name = config('queue.default');

        if (! is_string($name) || $name === '') {
            return null;
        }

        if (config('queue.connections.'.$name.'.driver') !== 'redis') {
            return null;
        }

        return app(JobRepository::class);
    }
}
