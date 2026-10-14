<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON of one task lookup (research A5).
 *
 * An explicit allow-list: the description, the dates, the people and any billing value of the task never
 * leave through a lookup (D-10, T-07-36). Adding a key later is additive; removing one is a breaking change.
 *
 * @property-read Task $resource
 */
final class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $task = $this->resource;

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'reference' => $task->reference,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
        ];
    }
}
