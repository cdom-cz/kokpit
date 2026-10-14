<?php

declare(strict_types=1);

use App\Domain\Audit\KokpitCleanActivityLogAction;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/*
 * Activity rows older than activitylog.clean_after_days (365) are deleted every
 * night by the scheduled activitylog:clean command. The clean action runs the
 * delete as system: a console run has no user, and the fail-closed Partner scope
 * would otherwise reduce the delete to `1 = 0` and still report success (WR-04).
 */

/**
 * Writes one activity row dated the given number of days ago and returns its id.
 */
function activityPruneRow(int $daysAgo, ?string $logName = null): string
{
    $activity = activity($logName)
        ->createdAt(now()->subDays($daysAgo))
        ->log('Fictional entry from '.$daysAgo.' days ago');

    return (string) $activity->getKey();
}

/**
 * The ids left in the activity log, read around the scoped model (a guest sees nothing there).
 *
 * @return list<string>
 */
function activityPruneRemainingIds(): array
{
    $ids = DB::table('activity_log')->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    sort($ids);

    return array_values($ids);
}

/**
 * The scheduled events that run the activity log clean command.
 *
 * @return list<Event>
 */
function activityPruneEvents(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'activitylog:clean'),
    ));
}

it('wires the system-run clean action and a 365-day retention into the package configuration', function (): void {
    expect(config('activitylog.actions.clean_log'))->toBe(KokpitCleanActivityLogAction::class)
        ->and(config('activitylog.clean_after_days'))->toBe(365);
});

it('deletes rows older than 365 days in every log and keeps younger ones, with no signed-in user', function (): void {
    $this->freezeTime();

    $fiveYears = activityPruneRow(365 * 5);
    $archive366 = activityPruneRow(366, 'fictional-archive');
    $default364 = activityPruneRow(364);
    $archiveNow = activityPruneRow(0, 'fictional-archive');

    expect(activityPruneRemainingIds())->toHaveCount(4);

    expect(Artisan::call('activitylog:clean', ['--force' => true]))->toBe(0);

    $expected = [$default364, $archiveNow];
    sort($expected);

    expect(activityPruneRemainingIds())->toBe($expected)
        ->and(activityPruneRemainingIds())->not->toContain($fiveYears, $archive366);
});

it('needs the system wrapper: the plain package action deletes nothing in the same state', function (): void {
    $this->freezeTime();

    $old = activityPruneRow(400);

    expect((new CleanActivityLogAction)->execute(365))->toBe(0)
        ->and(activityPruneRemainingIds())->toContain($old);

    expect(app(KokpitCleanActivityLogAction::class)->execute(365))->toBe(1)
        ->and(activityPruneRemainingIds())->not->toContain($old);
});

it('restores the system flag after the prune', function (): void {
    $this->freezeTime();

    activityPruneRow(400);

    expect(app(PartnerContext::class)->isSystem())->toBeFalse();

    Artisan::call('activitylog:clean', ['--force' => true]);

    expect(app(PartnerContext::class)->isSystem())->toBeFalse();
});

it('schedules exactly one nightly one-server clean command with the retention left to config', function (): void {
    $events = activityPruneEvents();

    expect($events)->toHaveCount(1);

    $event = $events[0];

    expect($event->description)->toBe('kokpit-activity-log-prune')
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and((string) $event->command)->toContain('--force')
        ->and((string) $event->command)->not->toContain('--days');
});
