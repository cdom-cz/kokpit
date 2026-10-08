<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\ActivitySource;
use App\Domain\Operations\Alerts\ReportFailedJob;
use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\Indicators\PlaceholderIndicator;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the operations foundation: the activity source label and its queue
 * listeners, the Admin alert for a job that failed for good, and the health
 * indicator registry behind the System page. Later operations plans add their
 * listeners and bindings here.
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

        // One indicator per HealthSlot case (D-13). Every slot starts as a placeholder; a plan or
        // phase that measures a slot swaps its placeholder (plan 03-16 for the queue and scheduler
        // slots, Phases 8, 10 and 11 for the rest) and never leaves a slot without an indicator.
        $this->app->singleton(HealthIndicatorRegistry::class, function (): HealthIndicatorRegistry {
            $registry = new HealthIndicatorRegistry;

            foreach (HealthSlot::cases() as $slot) {
                $registry->register(new PlaceholderIndicator($slot));
            }

            return $registry;
        });
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
