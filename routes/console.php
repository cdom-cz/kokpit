<?php

declare(strict_types=1);

use App\Domain\Operations\Health\Heartbeats;
use App\Domain\Operations\Jobs\RecordWorkerHeartbeat;
use App\Domain\TimeTracking\Jobs\NotifyLongRunningTimers;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The production crontab runs schedule:run on every container, so every scheduled event must use
// onOneServer (closure events call name() first, which Laravel requires). The lock is taken in
// the shared Redis cache store: production CACHE_STORE=redis, refused otherwise by
// ProductionConfigGuard. ScheduleOnOneServerTest enforces it for every registered event.

// Liveness of the scheduler for the System page (D-12). Written every minute.
Schedule::call(fn () => app(Heartbeats::class)->recordScheduler())
    ->everyMinute()
    ->name('kokpit-heartbeat')
    ->onOneServer();

// A trivial queued job: it only ages when no worker takes it, which makes a dead worker
// visible as a growing "oldest pending job" even when nothing else is queued (FND-09).
Schedule::job(new RecordWorkerHeartbeat)
    ->everyMinute()
    ->name('kokpit-worker-heartbeat')
    ->onOneServer();

// Feeds the metrics graphs of the Horizon dashboard.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->name('kokpit-horizon-snapshot');

// One bell notice per timer that has run past kokpit.time.long_running_hours; it never stops the timer (TI-09, D-07).
Schedule::job(new NotifyLongRunningTimers)
    ->everyFiveMinutes()
    ->name('kokpit-long-running-timers')
    ->onOneServer();
