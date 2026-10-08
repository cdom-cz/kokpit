<?php

declare(strict_types=1);

namespace App\Domain\Operations\Jobs;

use App\Domain\Operations\Health\Heartbeats;

/**
 * Dispatched by the scheduler every minute. When a worker handles it, the System
 * page learns that a worker is alive; when no worker does, the job waits and the
 * "oldest pending job" slot grows, so a dead worker shows up even with nothing
 * else queued (FND-09).
 */
#[Idempotent(how: 'overwrites one cache timestamp, so a second run changes nothing')]
final class RecordWorkerHeartbeat extends KokpitJob
{
    public function handle(): void
    {
        app(Heartbeats::class)->recordWorker();
    }
}
