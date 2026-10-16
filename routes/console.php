<?php

declare(strict_types=1);

use App\Domain\Api\Jobs\PurgeExpiredIdempotencyKeys;
use App\Domain\Documents\Jobs\PruneExpiredDocumentArchives;
use App\Domain\Operations\Health\Heartbeats;
use App\Domain\Operations\Jobs\RecordWorkerHeartbeat;
use App\Domain\Reports\Jobs\PruneExpiredReportExports;
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

// Safety net: a running entry past kokpit.time.max_running_hours (48) is ended at its start plus that limit, never at now (hand-inserted entries).
Schedule::command('kokpit:time:end-overrun')
    ->hourly()
    ->name('kokpit-end-overrun-timers')
    ->onOneServer();

// Deletes idempotency keys past their 24 hours so api_idempotency_keys stays small (D-08).
Schedule::job(new PurgeExpiredIdempotencyKeys)
    ->daily()
    ->name('kokpit-api-idempotency-prune')
    ->onOneServer();

// Deletes activity rows older than activitylog.clean_after_days (365) once a night; retention lives in config only.
Schedule::command('activitylog:clean', ['--force'])
    ->daily()
    ->name('kokpit-activity-log-prune')
    ->onOneServer();

// The CNB list is published on Czech working days after 14:30 Prague time. The download runs at each configured
// hour (kokpit.rates.attempt_hours) Monday to Friday, always in Prague time so summer and winter time keep the
// same local hours. Synchronous on purpose: a dead queue worker must never swallow the failure alert. A failed
// attempt here only prints a warning and waits for the next hour; a public holiday is skipped by the command (D-03).
Schedule::command('kokpit:rates:download')
    ->cron('0 '.implode(',', array_map('intval', (array) config('kokpit.rates.attempt_hours'))).' * * 1-5')
    ->timezone('Europe/Prague')
    ->name('kokpit-cnb-rates')
    ->onOneServer();

// One more run after the last attempt. Only this run alerts the Admin, when the day still has no list, so the Admin
// is told once a day and never once per hourly attempt (D-04).
Schedule::command('kokpit:rates:download', ['--final'])
    ->cron('0 '.(int) config('kokpit.rates.final_hour').' * * 1-5')
    ->timezone('Europe/Prague')
    ->name('kokpit-cnb-rates-final')
    ->onOneServer();

// Deletes the file and the record of every report export past kokpit.exports.ttl_days (7), so no export outlives
// the promise of D-09. Runs as system, so it prunes the exports of every Admin.
Schedule::job(new PruneExpiredReportExports)
    ->daily()
    ->name('kokpit-report-export-prune')
    ->onOneServer();

// Deletes the object and the record of every ZIP archive past kokpit.documents.archive.ttl_days (7), so no archive of
// client files outlives the promise of D-08. A pending or failed record goes 7 days after its creation. Runs as
// system, so it prunes the archives of every Admin.
Schedule::job(new PruneExpiredDocumentArchives)
    ->daily()
    ->name('kokpit-document-archive-prune')
    ->onOneServer();
