<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Finance\Enums\TransactionType;
use App\Http\Controllers\Api\V1\TransactionCategoryController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The validated query string of GET /api/v1/transaction-categories.
 *
 * Modelled on {@see ListLookupsRequest}, but a class of its own because the default sort is `name`, not newest
 * first. The allowed filter keys and sorts are read from the constants of the controller, so an unknown key is a
 * 422 (the `array:` key list) and the query-builder's own 400 never reaches a client; a cursor the API did not
 * issue for the effective sort is refused the same way (see ApiCursor). The route middleware has already
 * authorized the token.
 */
final class ListTransactionCategoriesRequest extends FormRequest
{
    /**
     * The sort when none is asked for: by name, ascending.
     */
    private const string DEFAULT_SORT = 'name';

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
            'filter' => ['sometimes', 'array:'.implode(',', TransactionCategoryController::FILTERS)],
            'filter.type' => ['sometimes', 'string', Rule::in(array_column(TransactionType::cases(), 'value'))],
            'filter.archived' => ['sometimes', 'string', Rule::in(self::BOOLEAN_VALUES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxPerPage()],
            'cursor' => ['sometimes', 'bail', 'string', 'max:1024', ApiCursor::rule($this->cursorColumns())],
            'sort' => ['sometimes', 'string', Rule::in(self::sortValues())],
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
     * The effective sort expression, by name ascending by default.
     */
    public function sortExpression(): string
    {
        $sort = $this->input('sort');

        return is_string($sort) && in_array($sort, self::sortValues(), true) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * Every sort expression: each column ascending and descending.
     *
     * @return list<string>
     */
    private static function sortValues(): array
    {
        return [...TransactionCategoryController::SORTS, ...array_map(static fn (string $sort): string => '-'.$sort, TransactionCategoryController::SORTS)];
    }

    /**
     * The order columns a cursor of this list carries: the sort column of the effective sort and the id tiebreaker.
     *
     * @return array<string, 'uuid'|'instant'|'text'|'date'|'integer'>
     */
    private function cursorColumns(): array
    {
        $sortColumn = ltrim($this->sortExpression(), '-');

        return [
            $sortColumn => ApiCursor::kindOf($sortColumn),
            TransactionCategoryController::ID_COLUMN => ApiCursor::UUID,
        ];
    }

    private static function maxPerPage(): int
    {
        $max = config('kokpit.api.page_size.max');

        return is_int($max) ? $max : 200;
    }
}
