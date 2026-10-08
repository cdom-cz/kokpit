<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\ActivitySource;
use App\Domain\Operations\Alerts\ReportFailedJob;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the operations foundation: the activity source label and its queue
 * listeners, and the Admin alert for a job that failed for good. Later
 * operations plans add their listeners and bindings here.
 */
final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A singleton: the job depth must survive between the queue events of one worker process.
        $this->app->singleton(
            ActivitySource::class,
            fn (): ActivitySource => new ActivitySource(fn (): bool => $this->app->runningInConsole()),
        );
    }

    public function boot(): void
    {
        // JobProcessing enters; exactly one of JobProcessed (success) and
        // JobExceptionOccurred (retry or final failure) leaves.
        Event::listen(JobProcessing::class, static function (): void {
            app(ActivitySource::class)->enterJob();
        });
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], static function (): void {
            app(ActivitySource::class)->leaveJob();
        });

        // One alert per final failure, for every job including package jobs (D-11). The
        // listener catches everything so the failed_jobs record is never stopped.
        Event::listen(JobFailed::class, [ReportFailedJob::class, 'handle']);
    }
}
