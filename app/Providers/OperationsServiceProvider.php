<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\ActivitySource;
use App\Domain\Operations\Alerts\ReportFailedJob;
use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\Indicators\FailedJobsIndicator;
use App\Domain\Operations\Health\Indicators\LastRateDateIndicator;
use App\Domain\Operations\Health\Indicators\OldestPendingJobIndicator;
use App\Domain\Operations\Health\Indicators\PlaceholderIndicator;
use App\Domain\Operations\Health\Indicators\SchedulerHeartbeatIndicator;
use App\Domain\Operations\Health\Indicators\StripeConfigIndicator;
use App\Domain\Operations\Health\Indicators\UnprocessedWebhooksIndicator;
use App\Domain\Operations\Health\Indicators\UnsentInvoiceEmailsIndicator;
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

        // One indicator per HealthSlot case (D-13). The queue, scheduler and last rate date slots
        // (Phase 8), the unsent invoice e-mails slot (Phase 10) and the two Stripe slots (Phase 11)
        // are measured for real; a slot without a real indicator starts as a placeholder.
        // A slot never stays without an indicator, and register() refuses a second one, so the
        // placeholder is only registered for a slot no real indicator covers.
        $this->app->singleton(HealthIndicatorRegistry::class, function (): HealthIndicatorRegistry {
            $registry = new HealthIndicatorRegistry;
            $real = [
                new FailedJobsIndicator,
                new OldestPendingJobIndicator,
                new SchedulerHeartbeatIndicator,
                new LastRateDateIndicator,
                new UnprocessedWebhooksIndicator,
                new StripeConfigIndicator,
                new UnsentInvoiceEmailsIndicator,
            ];

            foreach ($real as $indicator) {
                $registry->register($indicator);
            }

            foreach (HealthIndicatorRegistry::missingSlots($real) as $slot) {
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
