<?php

declare(strict_types=1);

namespace App\Domain\Operations\Alerts;

use Filament\Facades\Filament;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Raises the Admin alert when a job has failed for good (D-11).
 *
 * Listens to JobFailed, which Laravel dispatches once per final failure for
 * every job, package jobs included. It must never throw: an exception here
 * would stop the listener that writes the failed_jobs row.
 *
 * The alert holds the job name, queue, attempts, exception class, the failed
 * job id and a shortened first line of the message. Never the job payload and
 * never a stack trace: messages and payloads can carry SQL values, connection
 * details or personal data, and the full detail stays in failed_jobs and the log.
 */
final class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        try {
            $job = $event->job;
            $name = $job->resolveName();

            app(AdminAlerter::class)->alert('failed-job:'.$name, new OperationalAlert(
                __('kokpit.alerts.failed_job.title'),
                implode("\n", [
                    __('kokpit.alerts.failed_job.job', ['job' => $name]),
                    __('kokpit.alerts.failed_job.queue', ['queue' => $job->getQueue(), 'connection' => $event->connectionName]),
                    __('kokpit.alerts.failed_job.attempts', ['attempts' => $job->attempts()]),
                    __('kokpit.alerts.failed_job.error', ['class' => $event->exception::class, 'message' => $this->firstLine($event->exception)]),
                    __('kokpit.alerts.failed_job.failed_job_id', ['id' => (string) $job->uuid()]),
                ]),
                $this->systemUrl(),
            ));
        } catch (Throwable $e) {
            try {
                Log::critical('Failed-job report could not be built', [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // The log itself is down; the failed_jobs write must still go on.
            }
        }
    }

    /**
     * The first line of the message, cut to the configured length.
     */
    private function firstLine(Throwable $e): string
    {
        $lines = preg_split('/\R/u', $e->getMessage());
        $first = is_array($lines) ? ($lines[0] ?? '') : '';

        return mb_substr($first, 0, max(0, (int) config('kokpit.alerts.message_max_length')));
    }

    /**
     * The System page when it exists (plan 03-15), the panel home until then.
     */
    private function systemUrl(): ?string
    {
        try {
            if (Route::has('filament.admin.pages.system')) {
                return route('filament.admin.pages.system');
            }

            return Filament::getPanel('admin')->getUrl();
        } catch (Throwable) {
            return null;
        }
    }
}
