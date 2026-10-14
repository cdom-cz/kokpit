<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The validated envelope of POST /api/v1/time-entries/batch (D-14).
 *
 * Only the envelope is checked here: `entries` is a list of 1 to `kokpit.api.batch_max_items` items. The items
 * themselves are validated one by one in the controller, so one bad item never fails the whole batch.
 */
final class BatchStoreTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('kokpit.api.batch_max_items')],
        ];
    }

    /**
     * The field description of the generated documentation (Scribe). The fields of an item are documented
     * with attributes on the controller, because this request has no per-item rules.
     *
     * @return array<string, array{description: string, example?: mixed, type?: string}>
     */
    public function bodyParameters(): array
    {
        return [
            'entries' => [
                'description' => 'The time entries to record, 1 to 100 JSON objects. Each one takes the fields of a single create.',
                'type' => 'object[]',
            ],
        ];
    }
}
