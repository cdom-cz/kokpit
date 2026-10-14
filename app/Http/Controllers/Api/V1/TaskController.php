<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Identity\ApiAbility;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Http\Requests\Api\V1\ListLookupsRequest;
use App\Http\Resources\Api\V1\TaskResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\UrlParam;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Read-only lookup of tasks (D-12): the tasks an importer may write time against, found by UUID or `KEY-N`.
 *
 * Only selectable tasks answer: not archived and in a project that is selectable. Every other reference, a
 * malformed one included, answers the same Czech 404 without touching the database with an unvalidated string.
 */
#[Group('Lookups')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left.')]
final class TaskController
{
    /**
     * The filters of the list, `filter[<name>]`; `name` matches the title. The query-builder allow-list, the
     * request rules and the generated documentation all read this one list.
     */
    public const array FILTERS = ['name', 'project'];

    /**
     * The columns the list can be sorted by; a leading `-` sorts descending.
     */
    public const array SORTS = ['title', 'created_at'];

    /**
     * The tiebreaker of every sort and a key of every cursor of the list.
     */
    public const string ID_COLUMN = 'tasks.id';

    #[Endpoint(
        'List tasks',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns the tasks an entry can be recorded against (archived tasks and tasks of archived projects or clients are left out), newest first, a page at a time. Follow `meta.next_cursor` with the `cursor` parameter until it is `null`. A task carries no description, dates or billing value.',
    )]
    #[QueryParam('filter[name]', 'string', 'Only tasks whose title contains this text, ignoring case. A comma, a percent sign or an underscore is matched literally. A title finds a task; it never identifies one for a write, use the UUID or the reference.', required: false, example: 'ukáz')]
    #[QueryParam('filter[project]', 'string', 'Only tasks of this project: its UUID or its key, for example `DEMO`. An unknown key matches no task.', required: false, example: 'DEMO')]
    #[QueryParam('cursor', 'string', 'The `meta.next_cursor` of the previous page of this list, sent with the same `sort`. Any other value answers 422.', required: false, example: 'No-example')]
    #[QueryParam('per_page', 'integer', 'Tasks per page, 1 to 200. Default 50.', required: false, example: 50)]
    #[QueryParam('sort', 'string', 'Sort by `created_at` (default, newest first as `-created_at`) or `title`; a leading `-` sorts descending. Titles sort in the plain database order with an id tiebreaker, not in Czech alphabetical order.', required: false, example: '-created_at', enum: ['created_at', '-created_at', 'title', '-title'])]
    #[Response(LookupExamples::TASK_LIST, 200, 'A page of tasks.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A filter, the page size, the cursor or the sort is not valid.')]
    public function index(ListLookupsRequest $request, ReferenceResolver $refs): AnonymousResourceCollection
    {
        $descending = str_starts_with($request->sortExpression(), '-');

        $tasks = QueryBuilder::for(Task::query()->whereIn('tasks.project_id', Project::query()->selectable()->select('projects.id')))
            ->allowedFilters(
                // The name of the list is the title of a task.
                AllowedFilter::partial('name', 'title'),
                // A key that matches nothing narrows the list to nothing; it is not an error.
                AllowedFilter::callback('project', static function (Builder $query, mixed $value) use ($refs): Builder {
                    $id = is_string($value) ? $refs->projectId($value) : null;

                    return $id === null ? $query->whereRaw('false') : $query->where('tasks.project_id', $id);
                }),
            )
            ->allowedSorts(...self::SORTS)
            ->defaultSort('-created_at')
            // The id breaks ties between tasks that share the sorted value, in the direction of the sort,
            // so a cursor page never skips or repeats a row.
            ->orderBy(self::ID_COLUMN, $descending ? 'desc' : 'asc')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    #[Endpoint(
        'Show a task',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns one task by its UUID or its reference, for example `DEMO-1`. Archived tasks, tasks of archived projects and tasks of archived clients are not found. The answer carries no description, dates or billing value.',
    )]
    #[UrlParam('id', 'string', 'Task UUID or task reference such as `DEMO-1`.', example: 'DEMO-1')]
    #[Response(LookupExamples::TASK, 200, 'The task.')]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No selectable task has this UUID or reference.')]
    public function show(string $task, ReferenceResolver $refs): TaskResource
    {
        $found = $refs->selectableTask($task);

        if ($found === null) {
            abort(404, __('api.errors.not_found'));
        }

        return new TaskResource($found);
    }
}
