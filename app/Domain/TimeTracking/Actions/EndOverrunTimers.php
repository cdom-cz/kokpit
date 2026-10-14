<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Domain\TimeTracking\TimerLock;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The hourly safety net: ends every running entry that has run longer than
 * `kokpit.time.max_running_hours` (48), mainly hand-inserted ones. It returns how
 * many entries it ended.
 *
 * The end is `started_at` plus the limit, never now. The stored duration is
 * therefore exactly the limit (172800 s) however old the start is, so the generated
 * integer column `duration_seconds` cannot overflow (SQLSTATE 22003). StopTimer is not
 * reused for this: its `max(now, started_at)` end would overflow for an entry decades
 * old. Because the start is more than the limit before now, `started_at` plus the limit
 * is always before now and after the start, so `time_entries_end_check` holds.
 *
 * Only running, unbilled rows are written. A billed entry cannot be running
 * (`time_entries_billed_finished_check`), and the locked re-read filters
 * `billing_state = unbilled` too, so the frozen-row guard (KP001) is never reached.
 *
 * Each entry is one transaction. It takes the owner's TimerLock first, the same lock
 * order as StartTimer and StopTimer, and only then re-reads the row FOR UPDATE with
 * every condition checked again, so an entry stopped, edited or ended in between is
 * skipped. The end is a model save, so the `ended_at` change writes one `updated`
 * activity row (source `console`) through the allowlist of TimeEntry.
 *
 * There is no actor: the Action runs only as a system run and refuses to run
 * otherwise, because without the system flag the fail-closed Partner scope would make
 * every query return zero rows and the run would be a silent no-op.
 */
final class EndOverrunTimers
{
    public function __construct(private readonly TimerLock $timerLock) {}

    public function handle(): int
    {
        if (! app(PartnerContext::class)->isSystem()) {
            throw new LogicException('EndOverrunTimers must run as a system run.');
        }

        $hours = max(1, (int) config('kokpit.time.max_running_hours'));
        $now = TimerClock::now();
        $cutoff = $now->subHours($hours);

        $candidates = TimeEntry::query()
            ->whereNull('ended_at')
            ->where('started_at', '<', $cutoff)
            ->with('user')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        $ended = 0;

        foreach ($candidates as $candidate) {
            $owner = $candidate->user;

            // The foreign key is not null, so a missing owner cannot happen; the guard
            // only keeps the types honest.
            if ($owner === null) {
                continue;
            }

            $ended += DB::transaction(function () use ($owner, $candidate, $hours, $cutoff): int {
                $this->timerLock->lock($owner);

                $row = TimeEntry::query()
                    ->whereKey($candidate->getKey())
                    ->whereNull('ended_at')
                    ->where('billing_state', BillingState::Unbilled)
                    ->where('started_at', '<', $cutoff)
                    ->lockForUpdate()
                    ->first();

                // Stopped, edited or ended by someone else since the candidate read.
                if ($row === null) {
                    return 0;
                }

                $row->forceFill(['ended_at' => TimerClock::truncate($row->started_at->addHours($hours))])->save();

                return 1;
            });
        }

        return $ended;
    }
}
