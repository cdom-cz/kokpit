<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Text\RichText;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Adds a comment to a task or a subtask (TA-04, D-08, D-10).
 *
 * The actor needs the `comment` ability on the task: the Admin always has it, a
 * Partner only on a task of a project they can see. The body is cleaned with
 * RichText::clean before it is stored, whoever wrote it; a body with no text
 * left after cleaning is a field error on `body`, and so is one over the length
 * limit. The task, the author and both flags are written with `forceFill`, so
 * nothing but the cleaned body comes from the request.
 *
 * Comments are append-only and never written to the activity log.
 */
final class AddTaskComment
{
    public function handle(User $actor, Task $task, string $body, bool $internal = false, bool $escalation = false): TaskComment
    {
        Gate::forUser($actor)->authorize('comment', $task);

        $clean = $this->cleanBody($body);

        $comment = new TaskComment(['body' => $clean]);
        $comment->forceFill([
            'task_id' => $task->getKey(),
            'author_id' => $actor->getKey(),
            'is_internal' => $internal,
            'is_escalation' => $escalation,
        ])->save();

        return $comment;
    }

    /**
     * @throws ValidationException a field error on `body` for an empty or too long body
     */
    private function cleanBody(string $body): string
    {
        try {
            $clean = RichText::clean($body);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['body' => __('kokpit.tasks.errors.body_too_long')]);
        }

        if ($clean === null) {
            throw ValidationException::withMessages(['body' => __('kokpit.tasks.errors.body_empty')]);
        }

        return $clean;
    }
}
