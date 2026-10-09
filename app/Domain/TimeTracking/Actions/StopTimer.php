<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Domain\TimeTracking\TimerLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Stops the running timer of the user and keeps its entry (D-02).
 *
 * Idempotent: with nothing running, or when the caller names an entry id that
 * is no longer the running one (a second tab, a double click, a start in
 * between), it does nothing and returns null, so it can never overwrite an
 * `ended_at` or stop a newer timer by mistake. The stop takes the same per-user
 * TimerLock as StartTimer, so a start and a stop of one user never
 * interleave; the clock is read after the lock.
 *
 * The entry is stopped at `max(now, started_at)`, so a start ahead of the clock
 * (clock skew between containers) cannot violate `ended_at >= started_at`.
 * Archiving a task, project or client never stops a timer, and the archived
 * context does not matter here: the row is found by user and state alone.
 *
 * Authorization comes first: `create` on TimeEntry refuses a Partner before any
 * query, then `update` on the found row.
 */
final class StopTimer
{
    public function __construct(private readonly TimerLock $timerLock) {}

    /**
     * @return TimeEntry|null the stopped entry, null when there was nothing to stop
     */
    public function handle(User $actor, ?string $expectedEntryId = null): ?TimeEntry
    {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);

        return DB::transaction(function () use ($actor, $expectedEntryId): ?TimeEntry {
            $this->timerLock->lock($actor);

            // Read after the lock, so a waiting stop sees the instant of its own turn.
            $now = TimerClock::now();

            $running = TimeEntry::query()
                ->where('user_id', $actor->getKey())
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($running === null || ($expectedEntryId !== null && $running->getKey() !== $expectedEntryId)) {
                return null;
            }

            Gate::forUser($actor)->authorize('update', $running);

            $running->forceFill(['ended_at' => $now->max($running->started_at)])->save();

            return $running->refresh();
        });
    }
}
