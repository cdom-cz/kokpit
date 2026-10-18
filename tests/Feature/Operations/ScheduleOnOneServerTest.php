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

    expect($descriptions)->toContain('kokpit-heartbeat', 'kokpit-worker-heartbeat', 'kokpit-horizon-snapshot', 'kokpit-long-running-timers', 'kokpit-end-overrun-timers', 'kokpit-api-idempotency-prune', 'kokpit-activity-log-prune', 'kokpit-cnb-rates', 'kokpit-cnb-rates-final', 'kokpit-report-export-prune', 'kokpit-document-archive-prune', 'kokpit-payment-link-sweep');
});

it('schedules the CNB download at the configured Prague hours and the final run an hour later', function (): void {
    $events = [];

    foreach (app(Schedule::class)->events() as $event) {
        $events[(string) $event->description] = $event;
    }

    expect($events['kokpit-cnb-rates']->expression)->toBe('0 15,16,17 * * 1-5')
        ->and($events['kokpit-cnb-rates']->timezone)->toBe('Europe/Prague')
        ->and($events['kokpit-cnb-rates']->command)->toContain('kokpit:rates:download')->not->toContain('--final')
        ->and($events['kokpit-cnb-rates-final']->expression)->toBe('0 18 * * 1-5')
        ->and($events['kokpit-cnb-rates-final']->timezone)->toBe('Europe/Prague')
        ->and($events['kokpit-cnb-rates-final']->command)->toContain('kokpit:rates:download')->toContain('--final');
});

it('schedules the export prune daily on one server', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => $event->description === 'kokpit-report-export-prune',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->onOneServer)->toBeTrue()
        ->and($events[0]->expression)->toBe('0 0 * * *');
});

it('schedules the document archive prune daily on one server', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => $event->description === 'kokpit-document-archive-prune',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->onOneServer)->toBeTrue()
        ->and($events[0]->expression)->toBe('0 0 * * *');
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
