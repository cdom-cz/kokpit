<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Billing\BillableDefault;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Domain\TimeTracking\TimeEntryInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Starts a timer for a client and stops the running one of the same user.
 *
 * Starting stops the running timer and keeps its entry, without a question
 * (D-02). The stop and the start share one transaction, one per-user advisory
 * lock and one instant: the clock is read after the lock, truncated to the
 * whole second, so the stopped entry ends exactly when the new one starts and
 * there is no gap or overlap. The partial unique index
 * time_entries_one_running_per_user is the database backstop behind the lock.
 *
 * A running entry whose start lies after the clock (clock skew between
 * containers) is stopped at its own start, never before it, so the
 * `ended_at >= started_at` check cannot fail. A start in the same second as the
 * running entry therefore keeps a zero-length entry.
 *
 * A start names a client, a project or a task. A task is enough: the project and
 * the client are derived from the task row read under a share lock, so a forged
 * combination can never be stored. The context rules and their field errors live
 * in TimeEntryInput::context(); a description over 1000 characters is the field
 * error `description`. `billable` is the given bool, or the D-03 default of the
 * task when it is null. Ids and instants are set with `forceFill`, so the
 * payload can never smuggle them in.
 *
 * Lock order: the per-user timer lock, then the task, project and client rows
 * FOR SHARE, then the running entry FOR UPDATE. ArchiveTask takes the board lock
 * and then the task row FOR UPDATE and never the timer lock, so there is no cycle.
 *
 * @phpstan-type TimerData array{client_id?: mixed, project_id?: mixed, task_id?: mixed, description?: mixed, billable?: mixed}
 */
final class StartTimer
{
    public function __construct(private readonly BillableDefault $billableDefault) {}

    /**
     * @param  TimerData  $data
     * @return array{entry: TimeEntry, stopped: TimeEntry|null}
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): array
    {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);

        return DB::transaction(function () use ($actor, $data): array {
            $this->lockTimerOf($actor);

            // Read after the lock, so a waiting start sees the instant of its own turn.
            $now = TimerClock::now();

            $context = TimeEntryInput::context($data);
            $description = TimeEntryInput::description($data['description'] ?? null);
            $billable = is_bool($data['billable'] ?? null)
                ? $data['billable']
                : $this->billableDefault->for($context['task']);

            $running = TimeEntry::query()
                ->where('user_id', $actor->getKey())
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($running !== null) {
                $running->forceFill(['ended_at' => $now->max($running->started_at)])->save();
                $running->refresh();
            }

            $entry = (new TimeEntry(['description' => $description, 'billable' => $billable]))->forceFill([
                'user_id' => $actor->getKey(),
                'client_id' => $context['client']->getKey(),
                'project_id' => $context['project']?->getKey(),
                'task_id' => $context['task']?->getKey(),
                'started_at' => $now,
            ]);
            $entry->save();
            $entry->refresh();

            return ['entry' => $entry, 'stopped' => $running];
        });
    }

    /**
     * One timer decision per user at a time; different users never wait on each other.
     */
    private function lockTimerOf(User $user): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['kokpit:timer:'.$user->getKey()]);
    }
}
