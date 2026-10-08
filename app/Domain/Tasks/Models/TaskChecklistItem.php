<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One todo item of the private checklist of a task or a subtask (TA-03).
 *
 * The checklist is the Admin's working note: it is closed to Partners by
 * DeniesPartners and the admin-only policy, so a Partner reads zero rows and
 * `$task->checklistItems` is empty for a Partner. The items are not written to
 * the activity log (working notes, not business identity).
 *
 * `task_id` is not fillable: the edit form saves the items through the
 * `Task::checklistItems()` relationship.
 *
 * @property string $id
 * @property string $task_id
 * @property string $text
 * @property bool $is_done
 * @property int $position
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'text',
    'is_done',
    'position',
])]
final class TaskChecklistItem extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'position' => 'integer',
        ];
    }
}
