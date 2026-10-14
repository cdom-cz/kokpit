<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\EndOverrunTimers;
use Illuminate\Console\Command;

/**
 * The hourly safety net of the scheduler (kokpit-end-overrun-timers): ends running time
 * entries that have run longer than the limit, at their start plus the limit.
 *
 * Output is the limit and the count only, never a client name or a description.
 */
class EndOverrunTimersCommand extends Command
{
    protected $signature = 'kokpit:time:end-overrun';

    protected $description = 'End running time entries that have run longer than kokpit.time.max_running_hours, at their start plus that limit';

    public function handle(EndOverrunTimers $action): int
    {
        // Console work has no signed-in user, so the fail-closed scopes would
        // silence it: the whole run is an explicit system run.
        $count = app(PartnerContext::class)->runAsSystem(fn (): int => $action->handle());

        $this->components->info(__('kokpit.overrun_timers.done', [
            'hours' => max(1, (int) config('kokpit.time.max_running_hours')),
            'count' => $count,
        ]));

        return self::SUCCESS;
    }
}
