<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\TimeEntryInput;
use App\Http\Controllers\Api\V1\TimeEntryController;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The validated query string of GET /api/v1/time-entries.
 *
 * Every bad value answers 422 in the Laravel validation shape. The unknown filter keys are rejected here
 * (the `array:` key list), so the query-builder's own 400 for a filter it does not allow never reaches a
 * client; a cursor the API did not issue for the effective sort is refused the same way (see ApiCursor). The route middleware has already authorized the token, so `authorize()` only says yes.
 */
final class ListTimeEntriesRequest extends FormRequest
{
    /**
     * The sort expressions a client may send; the first is the default.
     *
     * @var list<string>
     */
    private const array SORT_VALUES = ['-started_at', 'started_at', '-created_at', 'created_at'];

    /**
     * The spellings of a true or false filter value.
     *
     * @var list<string>
     */
    private const array BOOLEAN_VALUES = ['true', 'false', '1', '0'];

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
            'filter.project' => ['sometimes', 'string', self::reference(ReferenceResolver::PROJECT_KEY)],
            'filter.task' => ['sometimes', 'string', self::reference(ReferenceResolver::TASK_REFERENCE)],
            'filter.billable' => ['sometimes', 'string', Rule::in(self::BOOLEAN_VALUES)],
            'filter.billed' => ['sometimes', 'string', Rule::in(self::BOOLEAN_VALUES)],
            'filter.from' => ['sometimes', 'string', self::dayOrInstant()],
            'filter.to' => ['sometimes', 'string', self::dayOrInstant()],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxPerPage()],
            'cursor' => ['sometimes', 'bail', 'string', 'max:1024', ApiCursor::rule($this->cursorColumns())],
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

    /**
     * The order columns a cursor of this list carries: the sort column of the effective sort and the id tiebreaker.
     *
     * @return array<string, 'uuid'|'instant'|'text'>
     */
    private function cursorColumns(): array
    {
        $sortColumn = ltrim($this->sortExpression(), '-');

        return [
            $sortColumn => ApiCursor::kindOf($sortColumn),
            TimeEntryController::ID_COLUMN => ApiCursor::UUID,
        ];
    }

    /**
     * A UUID or a key of the given pattern.
     *
     * @param  non-empty-string  $pattern  the key pattern of ReferenceResolver
     */
    private static function reference(string $pattern): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($pattern): void {
            if (! is_string($value) || (! Str::isUuid($value) && preg_match($pattern, $value) !== 1)) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };
    }

    /**
     * A real calendar day `Y-m-d` (a Europe/Prague day) or an ISO 8601 instant with an offset or Z.
     */
    private static function dayOrInstant(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && (PragueDay::day($value) !== null || self::isInstant($value))) {
                return;
            }

            $fail(__('validation.regex', ['attribute' => $attribute]));
        };
    }

    private static function isInstant(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return false;
        }

        try {
            return TimeEntryInput::instant($value, 'filter') !== null;
        } catch (ValidationException) {
            return false;
        }
    }

    private static function maxPerPage(): int
    {
        $max = config('kokpit.api.page_size.max');

        return is_int($max) ? $max : 200;
    }
}
