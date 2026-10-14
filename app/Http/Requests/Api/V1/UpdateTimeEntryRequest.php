<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The validated body of PATCH /api/v1/time-entries/{timeEntry}.
 *
 * Every key is optional: an absent key keeps the stored value. The route middleware has already
 * authorized the token, so `authorize()` only says yes.
 */
final class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return TimeEntryPayload::rules(false);
    }

    /**
     * The field descriptions and examples of the generated documentation (Scribe).
     *
     * @return array<string, array{description: string, example: mixed, type?: string}>
     */
    public function bodyParameters(): array
    {
        return [
            'client_id' => [
                'description' => 'UUID of the client. A client that does not own the project answers 422 under `project_id`.',
                'example' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            ],
            'project_id' => [
                'description' => 'The project: its UUID or its key, for example `DEMO`. `null` clears it.',
                'example' => 'DEMO',
            ],
            'task_id' => [
                'description' => 'The task: its UUID or its reference, for example `DEMO-1`. `null` clears it and keeps the project.',
                'example' => 'DEMO-1',
            ],
            'started_at' => [
                'description' => 'Start instant, ISO 8601 with an offset or `Z`. A running entry may change its start.',
                'example' => '2026-10-12T10:00:00+02:00',
            ],
            'ended_at' => [
                'description' => 'End instant in the same form, strictly after the start. Not allowed on a running entry (422): the timer in the application ends it. Not combinable with `duration_seconds`.',
                'example' => '2026-10-12T11:45:00+02:00',
            ],
            'duration_seconds' => [
                'description' => 'Whole seconds from the stored (or the new) start, 1 to 2147483647, instead of `ended_at`. Not allowed on a running entry (422).',
                'example' => 6300,
            ],
            'description' => [
                'description' => 'Free text, at most 1000 characters. `null` clears it.',
                'example' => 'Example work, corrected',
            ],
            'billable' => [
                'description' => 'Whether the entry is billable.',
                'example' => false,
            ],
            'external' => [
                'description' => 'A free-form JSON object, with the same limits as on create (an object, at most 4096 bytes as compact JSON, at most 8 levels deep). Absent keeps the stored object, `null` clears it, an object replaces it whole (keys are not merged). A billed entry cannot be changed.',
                'type' => 'object',
                'example' => ['source' => 'demo-tracker', 'id' => 'A-1001'],
            ],
        ];
    }
}
