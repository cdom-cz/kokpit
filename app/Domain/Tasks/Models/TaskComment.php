<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One comment on a task or a subtask (TA-04, D-08).
 *
 * A Partner reads a comment exactly when it is not internal and its task is
 * visible to them: the constraint keeps only `is_internal = false` rows and
 * delegates the task rules to the Partner-scoped Task query, so an internal
 * comment is never returned to a Partner by any query, count or relation.
 *
 * Comments are append-only and are not written to the activity log, so the
 * text of an internal comment cannot leak through any history (D-07). Only the
 * body is fillable: the task, the author and both flags are set with
 * `forceFill` by AddTaskComment, never from a request.
 *
 * @property string $id
 * @property string $task_id
 * @property string $author_id
 * @property string $body
 * @property bool $is_internal
 * @property bool $is_escalation
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['body'])]
final class TaskComment extends KokpitModel implements PartnerIsolated
{
    use IsolatesPartners;

    /**
     * The non-internal comments of the tasks the Partner can see. The scoped
     * Task query applies the project rules itself.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query->where($this->qualifyColumn('is_internal'), false)
            ->whereIn($this->qualifyColumn('task_id'), Task::query()->select('tasks.id'));
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_escalation' => 'boolean',
        ];
    }
}
