<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Heartbeats;
use App\Domain\Operations\Health\Indicators\FailedJobsIndicator;
use App\Domain\Operations\Health\Indicators\OldestPendingJobIndicator;
use App\Domain\Operations\Health\Indicators\SchedulerHeartbeatIndicator;
use App\Domain\Operations\Jobs\RecordWorkerHeartbeat;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\RedisTestQueue;

/*
 * The real health indicators of the System page (D-12, D-13), measured against the
 * fixed thresholds in config/kokpit.php.
 */

/**
 * Logs one failed job through the queue failer, the way a worker does after the last attempt.
 */
function logFailedJob(): void
{
    app('queue.failer')->log(
        'redis',
        'fictional-queue',
        json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'Fictional\\Job'], JSON_THROW_ON_ERROR),
        new RuntimeException('Fictional failure'),
    );
}

it('reports Ok with a count of zero when no job has failed', function (): void {
    $result = (new FailedJobsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('0')
        ->and((new FailedJobsIndicator)->slot())->toBe(HealthSlot::FailedJobs);
});

it('reports Warning with the count once a job has failed for good', function (): void {
    logFailedJob();

    $result = (new FailedJobsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->value)->toBe('1');
});

it('takes the warning threshold from the configuration', function (): void {
    config(['kokpit.health.failed_jobs_warning_at' => 2]);
    logFailedJob();

    expect((new FailedJobsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    logFailedJob();

    expect((new FailedJobsIndicator)->check()->status)->toBe(HealthStatus::Warning)
        ->and((new FailedJobsIndicator)->check()->value)->toBe('2');
});

it('is the indicator the bound registry runs for the failed-jobs slot', function (): void {
    expect(app(HealthIndicatorRegistry::class)->indicators()[HealthSlot::FailedJobs->value])
        ->toBeInstanceOf(FailedJobsIndicator::class);
});

it('shows the Admin the failed-jobs slot as Warning with the count after a job failed', function (): void {
    logFailedJob();

    $html = (string) $this->actingAs(Canary::admin())->get('/admin/system')->assertOk()->getContent();

    expect($html)->toContain(__('enums.health_slot.failed_jobs'))
        ->toContain(__('enums.health_status.warning'))
        ->toContain('fi-color-warning')
        ->toContain(__('kokpit.system.failed_jobs.some'));
});

/**
 * The schedule event with this name, or null.
 */
function scheduleEventNamed(string $name): ?Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if ($event->description === $name) {
            return $event;
        }
    }

    return null;
}

/**
 * Records a scheduler heartbeat at a fixed instant, then moves the clock on by $secondsLater.
 */
function heartbeatAged(int $secondsLater): void
{
    Carbon::setTestNow('2026-07-01 10:00:00 UTC');
    app(Heartbeats::class)->recordScheduler();
    Carbon::setTestNow(Carbon::now()->addSeconds($secondsLater));
}

afterEach(function (): void {
    Carbon::setTestNow();

    if (isset($this->queue)) {
        RedisTestQueue::flush($this->queue);
    }
});

it('reports the scheduler as Ok when its heartbeat is 60 seconds old', function (): void {
    heartbeatAged(60);

    $result = (new SchedulerHeartbeatIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('60 s')
        ->and((new SchedulerHeartbeatIndicator)->slot())->toBe(HealthSlot::SchedulerHeartbeat);
});

it('reports the scheduler as Error when its heartbeat is 181 seconds old, and Ok at exactly 180', function (): void {
    heartbeatAged(181);

    expect((new SchedulerHeartbeatIndicator)->check()->status)->toBe(HealthStatus::Error);

    heartbeatAged(180);

    expect((new SchedulerHeartbeatIndicator)->check()->status)->toBe(HealthStatus::Ok);
});

it('reports Error with a never-recorded detail when the scheduler has not written a heartbeat', function (): void {
    expect(app(Heartbeats::class)->scheduler())->toBeNull();

    $result = (new SchedulerHeartbeatIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBeNull()
        ->and($result->detail)->toBe(__('kokpit.system.scheduler.never'));
});

it('moves the scheduler boundary with the configured threshold', function (): void {
    heartbeatAged(100);

    expect((new SchedulerHeartbeatIndicator)->check()->status)->toBe(HealthStatus::Ok);

    config(['kokpit.health.scheduler_heartbeat_error_after' => 99]);

    expect((new SchedulerHeartbeatIndicator)->check()->status)->toBe(HealthStatus::Error);
});

it('keeps the two heartbeats apart', function (): void {
    Carbon::setTestNow('2026-07-01 10:00:00 UTC');
    $heartbeats = app(Heartbeats::class);

    $heartbeats->recordWorker();

    expect($heartbeats->worker())->toBe(Carbon::now()->getTimestamp())
        ->and($heartbeats->scheduler())->toBeNull();

    $heartbeats->recordScheduler();

    expect($heartbeats->scheduler())->toBe(Carbon::now()->getTimestamp());
});

it('schedules the heartbeat every minute and running it writes the scheduler key', function (): void {
    $event = scheduleEventNamed('kokpit-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and(app(Heartbeats::class)->scheduler())->toBeNull();

    Carbon::setTestNow('2026-07-01 10:00:00 UTC');
    $event->run(app());

    expect(app(Heartbeats::class)->scheduler())->toBe(Carbon::now()->getTimestamp());
});

it('is the indicator the bound registry runs for the scheduler slot', function (): void {
    expect(app(HealthIndicatorRegistry::class)->indicators()[HealthSlot::SchedulerHeartbeat->value])
        ->toBeInstanceOf(SchedulerHeartbeatIndicator::class);
});

/**
 * Points the application at the real Redis queue on a queue name of this test, and fixes the
 * clock at 10:00 UTC on 2026-07-01.
 */
function useRedisQueueAt(object $test): void
{
    $test->queue = RedisTestQueue::name();
    config(['queue.default' => 'redis', 'queue.connections.redis.queue' => $test->queue]);
    Carbon::setTestNow('2026-07-01 10:00:00 UTC');
}

/**
 * Leaves one worker-heartbeat job in the test queue that was created $secondsAgo seconds before
 * the fixed clock, then puts the clock back.
 */
function pendingHeartbeatJobAged(object $test, int $secondsAgo): void
{
    RedisTestQueue::flush($test->queue);
    // The heartbeat job is unique while one waits; the flush above removed the waiting one, so free the lock too.
    (new UniqueLock(Cache::driver()))->release(new RecordWorkerHeartbeat);
    $now = Carbon::now();
    Carbon::setTestNow($now->copy()->subSeconds($secondsAgo));
    RecordWorkerHeartbeat::dispatch()->onConnection('redis')->onQueue($test->queue);
    Carbon::setTestNow($now);
}

it('reports Ok for an empty Redis queue', function (): void {
    useRedisQueueAt($this);

    $result = (new OldestPendingJobIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and((new OldestPendingJobIndicator)->slot())->toBe(HealthSlot::OldestPendingJob);
});

it('reports Warning for a job created 11 minutes ago and Error for one created 31 minutes ago', function (): void {
    useRedisQueueAt($this);

    pendingHeartbeatJobAged($this, 11 * 60);
    expect((new OldestPendingJobIndicator)->check()->status)->toBe(HealthStatus::Warning);

    pendingHeartbeatJobAged($this, 31 * 60);
    $result = (new OldestPendingJobIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('1860 s');
});

it('switches status exactly after the two thresholds', function (): void {
    useRedisQueueAt($this);

    $expected = [
        600 => HealthStatus::Ok,
        601 => HealthStatus::Warning,
        1800 => HealthStatus::Warning,
        1801 => HealthStatus::Error,
    ];

    foreach ($expected as $age => $status) {
        pendingHeartbeatJobAged($this, $age);

        expect((new OldestPendingJobIndicator)->check()->status)->toBe($status, "age {$age} s");
    }
});

it('takes both thresholds from the configuration', function (): void {
    useRedisQueueAt($this);
    pendingHeartbeatJobAged($this, 120);

    expect((new OldestPendingJobIndicator)->check()->status)->toBe(HealthStatus::Ok);

    config(['kokpit.health.oldest_pending_warning_after' => 60]);

    expect((new OldestPendingJobIndicator)->check()->status)->toBe(HealthStatus::Warning);

    config(['kokpit.health.oldest_pending_error_after' => 100]);

    expect((new OldestPendingJobIndicator)->check()->status)->toBe(HealthStatus::Error);
});

it('measures the queue it is given, not the configured one', function (): void {
    useRedisQueueAt($this);
    $other = RedisTestQueue::name();
    config(['queue.connections.redis.queue' => $other]);
    pendingHeartbeatJobAged($this, 31 * 60);

    expect((new OldestPendingJobIndicator)->check()->status)->toBe(HealthStatus::Ok)
        ->and((new OldestPendingJobIndicator($this->queue))->check()->status)->toBe(HealthStatus::Error);

    RedisTestQueue::flush($other);
});

it('shows when the worker last processed the heartbeat job, in Prague time, when it is known', function (): void {
    useRedisQueueAt($this);
    pendingHeartbeatJobAged($this, 11 * 60);

    $without = (new OldestPendingJobIndicator)->check();

    expect((string) $without->detail)->not->toContain('2026')
        ->and($without->detail)->toContain(__('kokpit.system.oldest_pending.worker_unknown'));

    app(Heartbeats::class)->recordWorker();

    $with = (new OldestPendingJobIndicator)->check();

    expect((string) $with->detail)->toContain('1. 7. 2026 12:00:00')
        ->and($with->detail)->not->toContain(__('kokpit.system.oldest_pending.worker_unknown'));
});

it('reports Not available, never Ok, when the queue connection is not Redis', function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-01 10:00:00 UTC');

    $result = (new OldestPendingJobIndicator)->check();

    expect($result->status)->toBe(HealthStatus::NotAvailable)
        ->and($result->detail)->toBe(__('kokpit.system.oldest_pending.not_redis'));
});

it('is the indicator the bound registry runs for the oldest-pending slot', function (): void {
    expect(app(HealthIndicatorRegistry::class)->indicators()[HealthSlot::OldestPendingJob->value])
        ->toBeInstanceOf(OldestPendingJobIndicator::class);
});

it('lets a worker clear the age: the heartbeat job is processed and the worker key is written', function (): void {
    useRedisQueueAt($this);
    pendingHeartbeatJobAged($this, 31 * 60);

    expect((new OldestPendingJobIndicator)->check()->status)->toBe(HealthStatus::Error)
        ->and(app(Heartbeats::class)->worker())->toBeNull();

    RedisTestQueue::work($this->queue);

    expect(app(Heartbeats::class)->worker())->not->toBeNull()
        ->and((new OldestPendingJobIndicator)->check()->status)->toBe(HealthStatus::Ok);
});

it('does not pile up heartbeat jobs while no worker takes them, and queues the next one after a worker ran it', function (): void {
    useRedisQueueAt($this);
    RedisTestQueue::flush($this->queue);
    (new UniqueLock(Cache::driver()))->release(new RecordWorkerHeartbeat);

    foreach (range(1, 5) as $minute) {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00 UTC')->addMinutes($minute));
        RecordWorkerHeartbeat::dispatch()->onConnection('redis')->onQueue($this->queue);
    }

    expect(RedisTestQueue::pending($this->queue))->toBe(1);

    RedisTestQueue::work($this->queue);
    expect(RedisTestQueue::pending($this->queue))->toBe(0);

    RecordWorkerHeartbeat::dispatch()->onConnection('redis')->onQueue($this->queue);
    expect(RedisTestQueue::pending($this->queue))->toBe(1);
});

it('holds the heartbeat uniqueness longer than the oldest-pending warning, so one waiting job covers the outage signal', function (): void {
    expect(new RecordWorkerHeartbeat)->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new RecordWorkerHeartbeat)->uniqueFor)->toBeGreaterThan((int) config('kokpit.health.oldest_pending_warning_after'));
});

it('handles the heartbeat job by writing the worker key and nothing else', function (): void {
    Carbon::setTestNow('2026-07-01 10:00:00 UTC');

    (new RecordWorkerHeartbeat)->handle();

    expect(app(Heartbeats::class)->worker())->toBe(Carbon::now()->getTimestamp())
        ->and(app(Heartbeats::class)->scheduler())->toBeNull();
});

it('schedules the worker heartbeat job every minute', function (): void {
    $event = scheduleEventNamed('kokpit-worker-heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and(app(Heartbeats::class)->worker())->toBeNull();

    // The sync queue of the test suite runs the dispatched job at once.
    $event->run(app());

    expect(app(Heartbeats::class)->worker())->not->toBeNull();
});
