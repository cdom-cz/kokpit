<?php

declare(strict_types=1);

use App\Domain\Operations\Health\Heartbeats;
use App\Domain\Operations\Jobs\RecordWorkerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Liveness of the scheduler for the System page (D-12). Written every minute.
Schedule::call(fn () => app(Heartbeats::class)->recordScheduler())
    ->everyMinute()
    ->name('kokpit-heartbeat');

// A trivial queued job: it only ages when no worker takes it, which makes a dead worker
// visible as a growing "oldest pending job" even when nothing else is queued (FND-09).
Schedule::job(new RecordWorkerHeartbeat)
    ->everyMinute()
    ->name('kokpit-worker-heartbeat');
