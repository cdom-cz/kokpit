<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskChecklistItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ticks or unticks one checklist item from the task page (TA-03). It is the only
 * writer of an item's done flag outside the edit page; the text and the position
 * are never touched here.
 *
 * The item id comes from the browser, so it is checked before any query: an id
 * that is not a UUID is a ModelNotFoundException and never reaches the uuid
 * column. Inside one transaction the task is re-read under a shared row lock
 * (the row is only read, but a concurrent archive, which locks it for update,
 * has to wait). The item is found only among the items of that task, so an item
 * of another task is a ModelNotFoundException, and so is every item for a Partner
 * (the model denies Partners). The `update` ability is then checked on the fresh
 * item row. An archived task has a read-only checklist: a ValidationException.
 *
 * The caller passes the target state, not "flip", and the row is written only when
 * the state differs, so a double click or a stale page cannot flip an item back.
 */
final class ToggleTaskChecklistItem
{
    /**
     * @throws ModelNotFoundException when the id is malformed, unknown, foreign or not visible to the actor
     * @throws ValidationException a field error on `checklist` when the task is archived
     */
    public function handle(User $actor, Task $task, string $itemId, bool $done): TaskChecklistItem
    {
        if (! Str::isUuid($itemId)) {
            throw (new ModelNotFoundException)->setModel(TaskChecklistItem::class, [$itemId]);
        }

        return DB::transaction(static function () use ($actor, $task, $itemId, $done): TaskChecklistItem {
            $locked = Task::query()->withTrashed()->whereKey($task->getKey())->sharedLock()->firstOrFail();

            $item = TaskChecklistItem::query()
                ->whereKey($itemId)
                ->where('task_id', $locked->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('update', $item);

            if ($locked->trashed()) {
                throw ValidationException::withMessages(['checklist' => __('kokpit.tasks.errors.checklist_archived')]);
            }

            if ($item->is_done !== $done) {
                $item->is_done = $done;
                $item->save();
            }

            return $item;
        });
    }
}
