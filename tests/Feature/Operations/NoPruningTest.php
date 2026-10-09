<?php

declare(strict_types=1);

use App\Domain\Audit\RefusingCleanActivityLogAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Activity records are kept indefinitely (D-09): the package's clean action is
 * replaced by one that refuses to delete, and nothing schedules the clean command.
 */

/**
 * Writes two activity rows dated long ago and returns how many rows the log holds.
 */
function oldActivityRowCount(): int
{
    activity()->log('Fictional old entry');
    activity()->log('Fictional older entry');
    DB::table('activity_log')->update(['created_at' => now()->subYears(5)]);

    return DB::table('activity_log')->count();
}

/**
 * Whether any event of the schedule runs a command with the given name.
 */
function scheduleRuns(Schedule $schedule, string $command): bool
{
    foreach ($schedule->events() as $event) {
        if (str_contains((string) $event->command, $command)) {
            return true;
        }
    }

    return false;
}

it('wires the refusing clean action into the package configuration', function (): void {
    expect(config('activitylog.actions.clean_log'))->toBe(RefusingCleanActivityLogAction::class);
});

it('fails the clean command loudly and keeps every activity row', function (): void {
    $before = oldActivityRowCount();

    expect($before)->toBeGreaterThanOrEqual(2);

    expect(fn () => Artisan::call('activitylog:clean', ['--days' => 1, '--force' => true]))
        ->toThrow(RuntimeException::class, 'D-09');

    expect(DB::table('activity_log')->count())->toBe($before);
});

it('refuses even with a log name and a very long retention', function (): void {
    $before = oldActivityRowCount();

    expect(fn () => Artisan::call('activitylog:clean', ['log' => 'default', '--days' => 36500, '--force' => true]))
        ->toThrow(RuntimeException::class);

    expect(DB::table('activity_log')->count())->toBe($before);
});

it('schedules no activity clean command', function (): void {
    expect(scheduleRuns(app(Schedule::class), 'activitylog:clean'))->toBeFalse();
});

it('would notice a scheduled activity clean command, so the schedule check is not vacuous', function (): void {
    $schedule = new Schedule;
    $schedule->command('activitylog:clean')->daily();

    expect(scheduleRuns($schedule, 'activitylog:clean'))->toBeTrue()
        ->and(scheduleRuns(new Schedule, 'activitylog:clean'))->toBeFalse();
});
