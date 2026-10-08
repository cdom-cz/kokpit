<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use ArrayAccess;
use Carbon\CarbonInterface;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Tags\HasTags;

/**
 * A task of a project, or one of its subtasks (one level only).
 *
 * A task is identified by its project-scoped reference `KEY-N`, allocated by
 * CreateTask from the per-project counter. Status and priority are the project
 * enums, freely switchable (D-01).
 *
 * A Partner reads a task exactly when the task's project is visible to them:
 * the constraint delegates to the Partner-scoped Project query, so the project
 * rules (own client, client-visible, not archived, client not archived) live in
 * one place (TA-01).
 *
 * Changes to the identity, status, priority, dates, people and escalation are
 * written to the activity log through the allowlist; the description and the
 * board position are never logged.
 *
 * Only the plain content columns are fillable. The ids, number, reference,
 * depth, position, people and escalation columns are set with `forceFill` by
 * trusted code, so a request can never re-point a task at another project or
 * person by mass assignment.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $parent_id
 * @property int $depth
 * @property int $number
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property ProjectStatus $status
 * @property ProjectPriority $priority
 * @property int $position
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $completed_at
 * @property string $assignee_id
 * @property string $requester_id
 * @property CarbonInterface|null $escalated_at
 * @property string|null $escalated_by_id
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'title',
    'description',
    'status',
    'priority',
    'start_date',
    'due_date',
])]
#[LoggedAttributes([
    'project_id',
    'parent_id',
    'reference',
    'title',
    'status',
    'priority',
    'start_date',
    'due_date',
    'assignee_id',
    'requester_id',
    'escalated_at',
])]
final class Task extends KokpitModel implements PartnerIsolated
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, IsolatesPartners, LogsAllowlistedActivity, SoftDeletes;

    use HasTags {
        detachTags as private detachTagsFromTrait;
    }

    /**
     * The tasks of the Partner's visible projects. The scoped Project query
     * applies the Partner constraint itself.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query->whereIn($this->qualifyColumn('project_id'), Project::query()->select('projects.id'));
    }

    /**
     * A task is addressed by its reference: /admin/tasks/KEY-N.
     */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * The package detaches every tag when a model is deleted. A soft delete keeps
     * the tags so a restore brings them back; only a force delete detaches them.
     *
     * @param  array<mixed>|ArrayAccess<int|string, mixed>  $tags
     */
    public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
    {
        if ($this->trashed() && ! $this->isForceDeleting()) {
            return $this;
        }

        return $this->detachTagsFromTrait($tags, $type);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The private todo checklist of the task, in the order the Admin left it.
     * Admin-only: a Partner reads no item.
     *
     * @return HasMany<TaskChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'number' => 'integer',
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
