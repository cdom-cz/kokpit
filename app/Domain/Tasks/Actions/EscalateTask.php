<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Escalates a task with a required comment (TA-07, D-06).
 *
 * The actor needs the `escalate` ability on the task (a Partner on a task of a
 * visible project of the own client; the Admin always). Inside one transaction
 * the task is re-read through the scoped query with a row lock, so two
 * escalations racing on the same task are serialised and a stale model instance
 * cannot slip a second flag past the check: an already escalated task is a field
 * error on `comment` and creates nothing. The reason is stored as one visible
 * escalation comment through AddTaskComment (an empty or too long reason is its
 * `body` error, re-keyed to `comment`), then the task records who escalated and
 * when. Only the escalation pair is written: priority, status and people stay as
 * they are, so an escalation never changes the priority (D-06).
 */
final class EscalateTask
{
    public function __construct(private readonly AddTaskComment $comments) {}

    /**
     * @throws ValidationException a field error on `comment` for an empty reason or an already escalated task
     */
    public function handle(User $actor, Task $task, string $comment): Task
    {
        Gate::forUser($actor)->authorize('escalate', $task);

        $fresh = DB::transaction(function () use ($actor, $task, $comment): Task {
            // The scoped query: a task that is not visible to the actor is a ModelNotFoundException.
            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->escalated_at !== null) {
                throw ValidationException::withMessages(['comment' => __('kokpit.tasks.errors.already_escalated')]);
            }

            try {
                $this->comments->handle($actor, $locked, $comment, internal: false, escalation: true);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['comment' => $e->errors()['body'] ?? [$e->getMessage()]]);
            }

            $locked->forceFill(['escalated_at' => now(), 'escalated_by_id' => $actor->getKey()])->save();

            return $locked;
        });

        // The caller's instance shows the stored flag, whatever it showed before.
        $task->refresh();

        return $fresh;
    }
}
