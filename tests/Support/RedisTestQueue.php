<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Real-Redis queue helper for tests. The DDEV Redis service is shared, so a
 * test uses a queue name of its own and removes exactly that queue's keys
 * afterwards; it never flushes a database.
 */
final class RedisTestQueue
{
    private const string CONNECTION = 'redis';

    /**
     * A queue name no other test or process uses.
     */
    public static function name(): string
    {
        return 'kokpit-test-'.Str::lower(Str::random(16));
    }

    /**
     * Deletes the keys of this queue (list, delayed, reserved, notify).
     */
    public static function flush(string $queue): void
    {
        $keys = ['queues:'.$queue, 'queues:'.$queue.':delayed', 'queues:'.$queue.':reserved', 'queues:'.$queue.':notify'];

        Redis::connection(self::redisConnectionName())->del($keys);
    }

    /**
     * Jobs still waiting, delayed or reserved in this queue.
     */
    public static function pending(string $queue): int
    {
        return Queue::connection(self::CONNECTION)->size($queue);
    }

    /**
     * Runs a worker over this queue until it is empty. Retries are released
     * with a zero wait, so one run normally handles every attempt; the loop
     * covers a worker that stopped early.
     */
    public static function work(string $queue, int $maxRuns = 5): void
    {
        for ($run = 0; $run < $maxRuns; $run++) {
            Artisan::call('queue:work', [
                'connection' => self::CONNECTION,
                '--queue' => $queue,
                '--stop-when-empty' => true,
                '--sleep' => 0,
                '--memory' => 4096,
            ]);

            if (self::pending($queue) === 0) {
                return;
            }
        }
    }

    private static function redisConnectionName(): string
    {
        $name = config('queue.connections.'.self::CONNECTION.'.connection');

        return is_string($name) ? $name : 'default';
    }
}
