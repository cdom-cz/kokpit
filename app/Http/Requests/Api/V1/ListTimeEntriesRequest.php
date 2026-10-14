<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Controllers\Api\V1\TimeEntryController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The validated query string of GET /api/v1/time-entries.
 *
 * Every bad value answers 422 in the Laravel validation shape. The unknown filter keys are rejected here
 * (the `array:` key list), so the query-builder's own 400 for a filter it does not allow never reaches a
 * client. The route middleware has already authorized the token, so `authorize()` only says yes.
 */
final class ListTimeEntriesRequest extends FormRequest
{
    /**
     * The sort expressions a client may send; the first is the default.
     *
     * @var list<string>
     */
    private const array SORT_VALUES = ['-started_at', 'started_at', '-created_at', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:'.implode(',', TimeEntryController::FILTERS)],
            'filter.client' => ['sometimes', 'string', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxPerPage()],
            'cursor' => ['sometimes', 'string', 'max:1024'],
            'sort' => ['sometimes', 'string', Rule::in(self::SORT_VALUES)],
        ];
    }

    /**
     * The number of items of one page: the requested size or the configured default.
     */
    public function perPage(): int
    {
        $default = config('kokpit.api.page_size.default');

        return $this->integer('per_page', is_int($default) ? $default : 50);
    }

    /**
     * The effective sort expression, with the default newest first.
     */
    public function sortExpression(): string
    {
        $sort = $this->input('sort');

        return is_string($sort) && in_array($sort, self::SORT_VALUES, true) ? $sort : self::SORT_VALUES[0];
    }

    private static function maxPerPage(): int
    {
        $max = config('kokpit.api.page_size.max');

        return is_int($max) ? $max : 200;
    }
}
