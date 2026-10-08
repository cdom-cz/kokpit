<?php

declare(strict_types=1);

namespace App\Domain\Operations\Jobs;

use App\Domain\Operations\Jobs\Middleware\RunsAsSystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class of every application job (D-10).
 *
 * Defaults, read by Laravel from this class and its parents; a subclass wins
 * with an attribute of its own: 3 attempts, a wait of 10 s, 60 s and 5 min
 * between them, and a 60 s timeout. The redis connection's retry_after (90 s)
 * stays above the timeout.
 *
 * Idempotence contract. A job may run more than once (a retry, or a worker
 * that died after the work and before the acknowledgement), so every concrete
 * subclass declares #[Idempotent(how: '...')] and keeps to this:
 *  - identify the work by a natural key (a rate date and currency, an invoice
 *    id), never by "whatever is pending";
 *  - check, then act: read the state the job is about to change and return
 *    when the work is already done;
 *  - let a unique constraint, not the check alone, be the last line of
 *    defence, so two concurrent runs cannot both write.
 *
 * Context. handle() runs in the system context through the fixed RunsAsSystem
 * middleware (a worker has no user, and the Partner scopes are fail-closed).
 * A subclass adds middleware through jobMiddleware(); it cannot drop the
 * system one.
 *
 * Delayed and retried jobs. The "oldest pending job" indicator of the System
 * page measures from the creation time of the job record, so a job that waits
 * in its backoff or was dispatched with a delay counts as old although the
 * queue is healthy. Read that indicator with this in mind.
 *
 * A final failure is reported by the JobFailed listener (ReportFailedJob): a
 * failed_jobs row and an Admin alert, whatever the subclass does.
 */
#[Tries(3)]
#[Backoff(10, 60, 300)]
#[Timeout(60)]
abstract class KokpitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @return array<int, object>
     */
    final public function middleware(): array
    {
        return [new RunsAsSystem, ...$this->jobMiddleware()];
    }

    /**
     * Middleware of the concrete job, run inside the system context.
     *
     * @return array<int, object>
     */
    protected function jobMiddleware(): array
    {
        return [];
    }
}
