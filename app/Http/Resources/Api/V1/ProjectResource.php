<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON of one project lookup (research A5).
 *
 * An explicit allow-list: no description, dates, visibility flag or billing value (the billing terms live in
 * a table this resource never reads). Enums leave as their string value.
 *
 * @property-read Project $resource
 */
final class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        return [
            'id' => $project->id,
            'client_id' => $project->client_id,
            'key' => $project->key,
            'name' => $project->name,
            'status' => $project->status->value,
            'priority' => $project->priority->value,
        ];
    }
}
