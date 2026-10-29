<?php

declare(strict_types=1);

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Tasks\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One task of the daily planner of a user, optionally linked to a Kokpit task (D-01).
 *
 * The title is a snapshot taken when the task was picked, so renaming, completing or archiving the
 * Kokpit task never changes it. The link is written only by AddSignalTask (never mass-assigned) and is
 * cleared by the database when the task is deleted for good; an archived task keeps it.
 *
 * `for_date` is a calendar day of the Europe/Prague planner, kept as the string `YYYY-MM-DD`.
 * Closed to Partners and constrained to the own rows (OwnedByUser).
 *
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property string $for_date
 * @property SignalCategory $category
 * @property bool $is_done
 * @property CarbonImmutable|null $completed_at
 * @property string|null $recurring_id
 * @property string|null $task_id
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['title', 'for_date', 'category', 'is_done', 'completed_at', 'recurring_id', 'position'])]
final class SignalTask extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    protected $table = 'signal_tasks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => SignalCategory::class,
            'is_done' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
