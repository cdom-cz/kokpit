<?php

declare(strict_types=1);

namespace App\Domain\Operations\Alerts;

use Filament\Facades\Filament;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Raises the Admin alert when a job has failed for good (D-11).
 *
 * Listens to JobFailed, which Laravel dispatches once per final failure for
 * every job, package jobs included. It must never throw: an exception here
 * would stop the listener that writes the failed_jobs row.
 *
 * The listener only builds the alert (no I/O). The delivery is deferred with
 * Laravel's defer(): the worker runs it on JobAttempted, which is raised after
 * every JobFailed listener, the one that writes the failed_jobs row included.
 * A slow or hanging mail transport therefore cannot cost the failure record.
 * It stays queue-independent: defer() runs in the same process, nothing is
 * pushed to a queue. The one exception is the worker's own timeout kill
 * (TimeoutExceededException): the process exits right after JobFailed and no
 * later hook runs, so that alert is delivered inline; the mail transport
 * timeout (mail.mailers.smtp.timeout) bounds how long that can take.
 *
 * The alert holds the job name, queue, attempts, exception class, the failed
 * job id and a sanitised, shortened first line of the message (AlertMessageSanitiser
 * removes hosts, IPs, ports, DSN fragments, quoted values and SQL tails). Never
 * the job payload and never a stack trace: messages and payloads can carry SQL
 * values, connection details or personal data, and the full detail stays in
 * failed_jobs and the log.
 */
final class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        try {
            $job = $event->job;
            $name = $job->resolveName();

            $alert = new OperationalAlert(
                __('kokpit.alerts.failed_job.title'),
                implode("\n", [
                    __('kokpit.alerts.failed_job.job', ['job' => $name]),
                    __('kokpit.alerts.failed_job.queue', ['queue' => $job->getQueue(), 'connection' => $event->connectionName]),
                    __('kokpit.alerts.failed_job.attempts', ['attempts' => $job->attempts()]),
                    __('kokpit.alerts.failed_job.error', ['class' => $event->exception::class, 'message' => $this->firstLine($event->exception)]),
                    __('kokpit.alerts.failed_job.failed_job_id', ['id' => (string) $job->uuid()]),
                ]),
                $this->systemUrl(),
            );

            $send = function () use ($name, $alert): void {
                try {
                    app(AdminAlerter::class)->alert('failed-job:'.$name, $alert);
                } catch (Throwable $e) {
                    $this->logCritical($e);
                }
            };

            if ($event->exception instanceof TimeoutExceededException) {
                $send();

                return;
            }

            // always: the job has failed, so the deferred callback must run although the attempt was not successful.
            defer($send, always: true);
        } catch (Throwable $e) {
            $this->logCritical($e);
        }
    }

    private function logCritical(Throwable $e): void
    {
        try {
            Log::critical('Failed-job report could not be built or delivered', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // The log itself is down; the failed_jobs write must still go on.
        }
    }

    /**
     * The first line of the message, sanitised (hosts, IPs, DSN fragments, SQL
     * and quoted values removed) and cut to the configured length.
     */
    private function firstLine(Throwable $e): string
    {
        return AlertMessageSanitiser::firstLine($e->getMessage(), (int) config('kokpit.alerts.message_max_length'));
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
