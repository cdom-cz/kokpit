<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Heartbeats;
use App\Domain\Operations\Health\Indicators\FailedJobsIndicator;
use App\Domain\Operations\Health\Indicators\SchedulerHeartbeatIndicator;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\Canary;

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
