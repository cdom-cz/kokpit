<?php

declare(strict_types=1);

use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Operations\Jobs\RecordWorkerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\ProvisioningPlan;

/**
 * Every supervisor of every configured environment, with the defaults merged in
 * the way Horizon merges them when it starts.
 *
 * @return array<string, array<string, array<string, mixed>>> environment => supervisor name => options
 */
function horizonSupervisors(): array
{
    $plan = new ProvisioningPlan('test-master', config('horizon.environments'), config('horizon.defaults'));

    return $plan->plan;
}

function horizonLongestTimeout(): int
{
    $timeouts = [];

    foreach (horizonSupervisors() as $supervisors) {
        foreach ($supervisors as $options) {
            $timeouts[] = (int) $options['timeout'];
        }
    }

    return max($timeouts);
}

function horizonScheduleEventNamed(string $name): ?Illuminate\Console\Scheduling\Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if ($event->description === $name) {
            return $event;
        }
    }

    return null;
}

it('configures the environments production, local and the wildcard', function (): void {
    expect(array_keys(horizonSupervisors()))->toEqualCanonicalizing(['production', 'local', '*']);
});

it('runs every supervisor on the redis queue with a timeout of 300 s and a single try', function (): void {
    foreach (horizonSupervisors() as $environment => $supervisors) {
        expect($supervisors)->not->toBeEmpty("environment {$environment} has no supervisor");

        foreach ($supervisors as $name => $options) {
            expect($options['connection'])->toBe('redis')
                ->and($options['queue'])->toContain(config('queue.connections.redis.queue'))
                ->and($options['timeout'])->toBe(300)
                ->and($options['tries'])->toBe(1);
        }
    }
});

it('keeps the supervisor timeout below the redis retry_after, so a running job is never handed out twice', function (): void {
    expect(config('queue.connections.redis.retry_after'))->toBe(330)
        ->and(horizonLongestTimeout())->toBeLessThan(config('queue.connections.redis.retry_after'));
});

it('keeps the supervisor timeout above the timeout of the base job', function (): void {
    $attributes = (new ReflectionClass(KokpitJob::class))->getAttributes(Timeout::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->timeout)->toBe(60)
        ->and(horizonLongestTimeout())->toBeGreaterThan($attributes[0]->newInstance()->timeout);
});

it('keeps the supervisor stop wait above the longest supervisor timeout', function (): void {
    $file = base_path('supervisor-horizon.ini');

    expect(is_file($file))->toBeTrue();

    $ini = parse_ini_file($file, true, INI_SCANNER_RAW);

    expect($ini)->toBeArray()
        ->and($ini)->toHaveKey('program:horizon')
        ->and((int) $ini['program:horizon']['stopwaitsecs'])->toBeGreaterThan(horizonLongestTimeout());
});

it('silences the worker heartbeat job so it does not flood the completed list', function (): void {
    expect(config('horizon.silenced'))->toContain(RecordWorkerHeartbeat::class);
});

it('schedules the metrics snapshot every five minutes on one server', function (): void {
    $event = horizonScheduleEventNamed('kokpit-horizon-snapshot');

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('horizon:snapshot')
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->onOneServer)->toBeTrue();
});

it('keeps the redis queue connection a RedisQueue, so the oldest-pending indicator keeps measuring', function (): void {
    expect(Queue::connection('redis'))->toBeInstanceOf(RedisQueue::class);
});

it('serves the dashboard under /horizon on the default Redis connection behind the web middleware', function (): void {
    expect(config('horizon.path'))->toBe('horizon')
        ->and(config('horizon.use'))->toBe('default')
        ->and(config('horizon.middleware'))->toBe(['web']);
});
