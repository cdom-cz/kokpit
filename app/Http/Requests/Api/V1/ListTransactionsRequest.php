<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Http\Controllers\Api\V1\TransactionController;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The validated query string of GET /api/v1/transactions.
 *
 * Every bad value answers 422 in the Laravel validation shape. The unknown filter keys are rejected here
 * (the `array:` key list), so the query-builder's own 400 for a filter it does not allow never reaches a
 * client; a cursor the API did not issue for the effective sort is refused the same way (see ApiCursor). Only
 * the shape of a value is checked: whether a category, client or project exists is not asked, because an
 * unknown one narrows the list to nothing, and `rules()` never queries the database (the documentation is
 * generated with the database unreachable). The route middleware has already authorized the token, so
 * `authorize()` only says yes.
 */
final class ListTransactionsRequest extends FormRequest
{
    /**
     * The sort expressions a client may send; the first is the default (newest first).
     *
     * @var list<string>
     */
    public const array SORT_VALUES = ['-transaction_date', 'transaction_date', '-amount', 'amount', '-created_at', 'created_at'];

    /**
     * A currency code as the API writes it: three upper case ASCII letters.
     */
    private const string CURRENCY = '/^[A-Z]{3}$/D';

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
            'filter' => ['sometimes', 'array:'.implode(',', TransactionController::FILTERS)],
            'filter.type' => ['sometimes', 'string', Rule::in(array_column(TransactionType::cases(), 'value'))],
            'filter.category' => ['sometimes', 'string', 'uuid'],
            'filter.client' => ['sometimes', 'string', 'uuid'],
            'filter.project' => ['sometimes', 'string', self::reference(ReferenceResolver::PROJECT_KEY)],
            'filter.date_from' => ['sometimes', 'string', self::day()],
            'filter.date_to' => ['sometimes', 'string', self::day()],
            'filter.currency' => ['sometimes', 'string', 'regex:'.self::CURRENCY],
            'filter.czk_source' => ['sometimes', 'string', Rule::in(array_column(CzkSource::cases(), 'value'))],
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
     * The order columns a cursor of this list carries: the ORDER BY column of the effective sort (the public name
     * of `amount` is `czk_minor` in the query) and the id tiebreaker.
     *
     * @return array<string, 'uuid'|'instant'|'text'|'date'|'integer'>
     */
    private function cursorColumns(): array
    {
        $sortColumn = TransactionController::SORT_COLUMNS[ltrim($this->sortExpression(), '-')];

        return [
            $sortColumn => ApiCursor::kindOf($sortColumn),
            TransactionController::ID_COLUMN => ApiCursor::UUID,
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
     * A real calendar day written `Y-m-d`; the column is a date, so an instant with a time is refused.
     */
    private static function day(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || PragueDay::day($value) === null) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };
    }

    private static function maxPerPage(): int
    {
        $max = config('kokpit.api.page_size.max');

        return is_int($max) ? $max : 200;
    }
}
