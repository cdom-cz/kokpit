<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON of one time entry.
 *
 * Every key is listed explicitly and none of the model is passed through, so a
 * column added to the table later never reaches the API by accident. The entry
 * carries no rate, price or amount (D-10): only whether it is billable and
 * whether it is billed. Instants are ISO 8601 in UTC, the duration is whole
 * seconds and null while the entry runs. `external` is the free-form JSON object an import helper
 * stored (D-15), or null; it stays a `stdClass` so an empty object is written as `{}`, never `[]`.
 *
 * @property-read TimeEntry $resource
 */
final class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'client_id' => $entry->client_id,
            'project_id' => $entry->project_id,
            'project_key' => $entry->project?->key,
            'task_id' => $entry->task_id,
            'task_reference' => $entry->task?->reference,
            'description' => $entry->description,
            'started_at' => self::instant($entry->started_at),
            'ended_at' => self::instant($entry->ended_at),
            'duration_seconds' => $entry->duration_seconds,
            'billable' => $entry->billable,
            'billing_state' => $entry->billing_state->value,
            'billed_at' => self::instant($entry->billed_at),
            'external' => $entry->external,
            'created_at' => self::instant($entry->created_at),
            'updated_at' => self::instant($entry->updated_at),
        ];
    }

    private static function instant(?CarbonInterface $instant): ?string
    {
        return $instant?->utc()->toIso8601String();
    }
}
