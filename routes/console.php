<?php

declare(strict_types=1);

use App\Domain\Operations\Health\Heartbeats;
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
