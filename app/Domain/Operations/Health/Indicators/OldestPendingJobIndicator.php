<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Heartbeats;
use App\Providers\LocalisationServiceProvider;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * How long the oldest job has waited on the Redis queue (D-12): Warning past
 * kokpit.health.oldest_pending_warning_after seconds, Error past
 * kokpit.health.oldest_pending_error_after.
 *
 * The age runs from the creation of the job record, not from the moment it
 * became ready, so a job dispatched with a delay shows its delay as age once it
 * is ready, and a job in its backoff keeps its original age. The base job's
 * backoff tops out at 300 s, below the warning; a job with a longer delay has to
 * account for this (see the KokpitJob docblock).
 *
 * A connection other than Redis cannot be measured and is NotAvailable, never Ok.
 * The detail names when a worker last processed the scheduled heartbeat job.
 */
final readonly class OldestPendingJobIndicator implements HealthIndicator
{
    public function __construct(private ?string $queue = null) {}

    public function slot(): HealthSlot
    {
        return HealthSlot::OldestPendingJob;
    }

    public function check(): HealthResult
    {
        $connectionName = config('queue.default');
        $connection = Queue::connection(is_string($connectionName) ? $connectionName : null);

        if (! $connection instanceof RedisQueue) {
            return new HealthResult(HealthStatus::NotAvailable, null, __('kokpit.system.oldest_pending.not_redis'));
        }

        $createdAt = $connection->creationTimeOfOldestPendingJob($this->queueName());

        if ($createdAt === null) {
            return new HealthResult(HealthStatus::Ok, null, $this->detail(__('kokpit.system.oldest_pending.empty')));
        }

        $age = max(0, now()->getTimestamp() - (int) $createdAt);

        if ($age > (int) config('kokpit.health.oldest_pending_error_after')) {
            return new HealthResult(HealthStatus::Error, $age.' s', $this->detail(__('kokpit.system.oldest_pending.error')));
        }

        if ($age > (int) config('kokpit.health.oldest_pending_warning_after')) {
            return new HealthResult(HealthStatus::Warning, $age.' s', $this->detail(__('kokpit.system.oldest_pending.warning')));
        }

        return new HealthResult(HealthStatus::Ok, $age.' s', $this->detail(__('kokpit.system.oldest_pending.ok')));
    }

    private function queueName(): string
    {
        if ($this->queue !== null) {
            return $this->queue;
        }

        $configured = config('queue.connections.redis.queue');

        return is_string($configured) ? $configured : 'default';
    }

    private function detail(string $text): string
    {
        $workerAt = app(Heartbeats::class)->worker();

        if ($workerAt === null) {
            return $text.' '.__('kokpit.system.oldest_pending.worker_unknown');
        }

        $time = Carbon::createFromTimestamp($workerAt, 'Europe/Prague')->format(LocalisationServiceProvider::DATE_TIME_SECONDS_FORMAT);

        return $text.' '.__('kokpit.system.oldest_pending.worker_last', ['time' => $time]);
    }
}
