<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TaskController;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The validated query string of the three lookup lists (clients, projects, tasks).
 *
 * One class serves all three: the allowed filter keys and sorts are read from the FILTERS and SORTS constants of
 * the controller the route resolves to, so an unknown key is a 422 (the `array:` key list) and the query-builder's
 * own 400 never reaches a client. A cursor the API did not issue for the list and the sort is refused the same way
 * (see ApiCursor). The route middleware has already authorized the token.
 */
final class ListLookupsRequest extends FormRequest
{
    /**
     * The sort when none is asked for: newest first.
     */
    private const string DEFAULT_SORT = '-created_at';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $filters = $this->filters();

        $rules = [
            'filter' => ['sometimes', 'array:'.implode(',', $filters)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::maxPerPage()],
            'cursor' => ['sometimes', 'bail', 'string', 'max:1024', ApiCursor::rule($this->cursorColumns())],
            'sort' => ['sometimes', 'string', Rule::in($this->sortValues())],
        ];

        if (in_array('name', $filters, true)) {
            $rules['filter.name'] = ['sometimes', 'string', 'max:200'];
        }

        if (in_array('client', $filters, true)) {
            $rules['filter.client'] = ['sometimes', 'string', 'uuid'];
        }

        if (in_array('project', $filters, true)) {
            $rules['filter.project'] = ['sometimes', 'string', self::reference(ReferenceResolver::PROJECT_KEY)];
        }

        return $rules;
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
     * The effective sort expression, newest first by default.
     */
    public function sortExpression(): string
    {
        $sort = $this->input('sort');

        return is_string($sort) && in_array($sort, $this->sortValues(), true) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * The filter keys of the matched controller.
     *
     * @return non-empty-list<string>
     */
    private function filters(): array
    {
        return match ($this->route()?->getControllerClass()) {
            ProjectController::class => ProjectController::FILTERS,
            TaskController::class => TaskController::FILTERS,
            default => ClientController::FILTERS,
        };
    }

    /**
     * Every sort expression of the matched controller: each column ascending and descending.
     *
     * @return list<string>
     */
    private function sortValues(): array
    {
        $sorts = match ($this->route()?->getControllerClass()) {
            ProjectController::class => ProjectController::SORTS,
            TaskController::class => TaskController::SORTS,
            default => ClientController::SORTS,
        };

        return [...array_map(static fn (string $sort): string => '-'.$sort, $sorts), ...$sorts];
    }

    /**
     * The id column of the matched controller: the tiebreaker of its sorts and a key of its cursors.
     */
    private function idColumn(): string
    {
        return match ($this->route()?->getControllerClass()) {
            ProjectController::class => ProjectController::ID_COLUMN,
            TaskController::class => TaskController::ID_COLUMN,
            default => ClientController::ID_COLUMN,
        };
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
            $this->idColumn() => ApiCursor::UUID,
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

    private static function maxPerPage(): int
    {
        $max = config('kokpit.api.page_size.max');

        return is_int($max) ? $max : 200;
    }
}
