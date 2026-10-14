<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The validated body of POST /api/v1/time-entries.
 *
 * The route middleware has already authorized the token, so `authorize()` only says yes. The rules check
 * shape only; the Action decides everything else (see TimeEntryPayload).
 */
final class StoreTimeEntryRequest extends FormRequest
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
        return TimeEntryPayload::rules(true);
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
                'description' => 'UUID of the client. Required unless the project or the task is given, because they fix the client. A client that does not own the project answers 422 under `project_id`.',
                'example' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9',
            ],
            'project_id' => [
                'description' => 'The project: its UUID or its key, for example `DEMO`. A name is refused. Optional; a task fixes the project.',
                'example' => 'DEMO',
            ],
            'task_id' => [
                'description' => 'The task: its UUID or its reference, for example `DEMO-1`. A name is refused. Optional. An archived task answers 422.',
                'example' => 'DEMO-1',
            ],
            'started_at' => [
                'description' => 'Start instant, ISO 8601 with an offset or `Z`, for example `2026-10-12T10:00:00+02:00`. An instant without an offset is refused. A fraction of a second is cut off.',
                'example' => '2026-10-12T10:00:00+02:00',
            ],
            'ended_at' => [
                'description' => 'End instant in the same form, strictly after the start. Give exactly one of `ended_at` and `duration_seconds`.',
                'example' => '2026-10-12T11:30:00+02:00',
            ],
            'duration_seconds' => [
                'description' => 'Whole seconds from the start, 1 to 2147483647, instead of `ended_at`. Give exactly one of the two.',
                'example' => 5400,
            ],
            'description' => [
                'description' => 'Free text, at most 1000 characters. Optional.',
                'example' => 'Example work on the DEMO project',
            ],
            'billable' => [
                'description' => 'Whether the entry is billable. When absent or null, the default of the task applies (billable unless the task is non-billable).',
                'example' => true,
            ],
            'external' => [
                'description' => 'A free-form JSON object for your own bookkeeping, for example the id of the entry in the system it was imported from. Optional. It must be an object (`{}` is fine, a list, a string or a number is refused with 422), at most 4096 bytes as compact JSON and nested at most 8 levels deep. It is returned with the entry (the order of its keys is not kept), never shown in the application and never used to look entries up or to skip duplicates.',
                'type' => 'object',
                'example' => ['source' => 'demo-tracker', 'id' => 'A-1001'],
            ],
        ];
    }
}
