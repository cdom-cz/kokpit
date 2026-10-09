<?php

declare(strict_types=1);

namespace App\Domain\Operations\Jobs;

use App\Domain\Operations\Health\Heartbeats;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Dispatched by the scheduler every minute. When a worker handles it, the System
 * page learns that a worker is alive; when no worker does, the job waits and the
 * "oldest pending job" slot grows, so a dead worker shows up even with nothing
 * else queued (FND-09).
 *
 * Unique for a while: while the worker is down the oldest waiting job already
 * carries the information (it ages), so the scheduler must not pile up a new
 * one every minute (1440 a day). One waiting job holds the lock for $uniqueFor
 * seconds, longer than the oldest-pending warning (600 s); a lost lock only
 * delays the next heartbeat by that time. The lock is released when the job
 * has run. No retryUntil: an expired job would be marked as failed by the
 * worker and raise a failed-job alert after every outage.
 */
#[Idempotent(how: 'overwrites one cache timestamp, so a second run changes nothing')]
final class RecordWorkerHeartbeat extends KokpitJob implements ShouldBeUnique
{
    /** Seconds a waiting heartbeat blocks a second one. */
    public int $uniqueFor = 900;

    public function handle(): void
    {
        app(Heartbeats::class)->recordWorker();
    }
}
