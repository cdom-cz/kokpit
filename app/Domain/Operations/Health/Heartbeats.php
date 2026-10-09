<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health;

use Illuminate\Support\Facades\Cache;

/**
 * The two liveness timestamps behind the System page: the scheduler writes one
 * every minute, and so does the queue worker through a scheduled job.
 *
 * Both live in the cache with Cache::forever, so an eviction policy that drops
 * keys with a time to live never takes them. A cache flush or a cache server
 * restart still wipes them: the slots show Error for up to one minute, until
 * the next scheduler run writes them again.
 */
final class Heartbeats
{
    private const string SCHEDULER_KEY = 'kokpit:heartbeat:scheduler';

    private const string WORKER_KEY = 'kokpit:heartbeat:worker';

    public function recordScheduler(): void
    {
        Cache::forever(self::SCHEDULER_KEY, now()->getTimestamp());
    }

    public function recordWorker(): void
    {
        Cache::forever(self::WORKER_KEY, now()->getTimestamp());
    }

    /**
     * Unix time of the last scheduler heartbeat, or null when none was recorded.
     */
    public function scheduler(): ?int
    {
        return $this->read(self::SCHEDULER_KEY);
    }

    /**
     * Unix time when a worker last processed the heartbeat job, or null when it never did.
     */
    public function worker(): ?int
    {
        return $this->read(self::WORKER_KEY);
    }

    private function read(string $key): ?int
    {
        $value = Cache::get($key);

        return is_int($value) ? $value : null;
    }
}
