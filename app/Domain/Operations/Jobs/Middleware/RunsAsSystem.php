<?php

declare(strict_types=1);

namespace App\Domain\Operations\Jobs\Middleware;

use App\Domain\Shared\Auth\PartnerContext;
use Closure;

/**
 * Runs a queued job in the system context (D-02, research Pitfall 2).
 *
 * A worker has no signed-in user, so the fail-closed Partner scopes would hide
 * every row from the job. This middleware is the single place that lifts that,
 * for exactly the duration of handle(); KokpitJob::middleware() always puts it
 * first and a subclass cannot remove it.
 */
final class RunsAsSystem
{
    public function handle(object $job, Closure $next): mixed
    {
        return app(PartnerContext::class)->runAsSystem(fn (): mixed => $next($job));
    }
}
