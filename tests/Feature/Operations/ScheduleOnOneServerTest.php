<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/**
 * Labels of the scheduled events that would run on every container.
 *
 * The production crontab runs `schedule:run` on every container (`allContainers: true` in
 * zerops.yml), so a task without onOneServer runs once per container. The lock lives in the
 * scheduler cache store, which must be the shared Redis store in production.
 *
 * @return list<string> one label per event without onOneServer (description, else command)
 */
function scheduleEventsRunningOnEveryServer(Schedule $schedule): array
{
    $labels = [];

    foreach ($schedule->events() as $event) {
        if ($event->onOneServer) {
            continue;
        }

        $labels[] = $event->description !== null && $event->description !== ''
            ? $event->description
            : ($event->command ?? 'an unnamed event');
    }

    return $labels;
}

it('registers the known scheduled events', function (): void {
    $descriptions = array_map(
        static fn ($event): ?string => $event->description,
        app(Schedule::class)->events(),
    );

    expect($descriptions)->toContain('kokpit-heartbeat', 'kokpit-worker-heartbeat', 'kokpit-horizon-snapshot', 'kokpit-long-running-timers');
});

it('runs every scheduled event on one server only', function (): void {
    expect(scheduleEventsRunningOnEveryServer(app(Schedule::class)))->toBe([]);
});

it('reports an event that would run on every container', function (): void {
    $schedule = new Schedule;
    $schedule->command('inspire')->everyMinute()->name('kokpit-probe-every-container');
    $schedule->call(static fn (): null => null)->everyMinute()->name('kokpit-probe-one-container')->onOneServer();

    expect(scheduleEventsRunningOnEveryServer($schedule))->toBe(['kokpit-probe-every-container']);

    // The check reads the same instance the console routes fill.
    app(Schedule::class)->command('inspire')->everyMinute()->name('kokpit-probe-added');

    expect(scheduleEventsRunningOnEveryServer(app(Schedule::class)))->toContain('kokpit-probe-added');
});
