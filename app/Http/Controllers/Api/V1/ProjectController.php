<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Identity\ApiAbility;
use App\Domain\Projects\Models\Project;
use App\Http\Requests\Api\V1\ListLookupsRequest;
use App\Http\Resources\Api\V1\ProjectResource;
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
 * Read-only lookup of projects (D-12): the projects an importer may record time against, found by name, key or UUID.
 *
 * Only selectable projects answer: not archived and belonging to a client that is not archived, the same set the
 * pickers of the application offer. The resource carries no description, dates or billing value (T-07-36).
 */
#[Group('Lookups')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left.')]
final class ProjectController
{
    /**
     * The filters of the list, `filter[<name>]`. The query-builder allow-list, the request rules and the
     * generated documentation all read this one list.
     */
    public const array FILTERS = ['name', 'client'];

    /**
     * The columns the list can be sorted by; a leading `-` sorts descending.
     */
    public const array SORTS = ['name', 'created_at'];

    #[Endpoint(
        'List projects',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns the projects an entry can be recorded against (archived projects and projects of archived clients are left out), newest first, a page at a time. Follow `meta.next_cursor` with the `cursor` parameter until it is `null`. A project carries no description, dates or billing value.',
    )]
    #[QueryParam('filter[name]', 'string', 'Only projects whose name contains this text, ignoring case. A comma, a percent sign or an underscore is matched literally. A name finds a project; it never identifies one for a write, use the UUID or the key.', required: false, example: 'ukáz')]
    #[QueryParam('filter[client]', 'string', 'Only projects of this client (UUID).', required: false, example: '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9')]
    #[QueryParam('cursor', 'string', 'The `meta.next_cursor` of the previous page.', required: false, example: 'No-example')]
    #[QueryParam('per_page', 'integer', 'Projects per page, 1 to 200. Default 50.', required: false, example: 50)]
    #[QueryParam('sort', 'string', 'Sort by `created_at` (default, newest first as `-created_at`) or `name`; a leading `-` sorts descending.', required: false, example: '-created_at', enum: ['created_at', '-created_at', 'name', '-name'])]
    #[Response(LookupExamples::PROJECT_LIST, 200, 'A page of projects.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A filter, the page size, the cursor or the sort is not valid.')]
    public function index(ListLookupsRequest $request): AnonymousResourceCollection
    {
        $descending = str_starts_with($request->sortExpression(), '-');

        $projects = QueryBuilder::for(Project::query()->selectable())
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::callback('client', static fn (Builder $query, mixed $value): Builder => $query->where('projects.client_id', $value)),
            )
            ->allowedSorts(...self::SORTS)
            ->defaultSort('-created_at')
            // The id breaks ties between projects that share the sorted value, in the direction of the sort,
            // so a cursor page never skips or repeats a row.
            ->orderBy('projects.id', $descending ? 'desc' : 'asc')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    #[Endpoint(
        'Show a project',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns one project by its UUID or its key, for example `DEMO`. Archived projects and projects of archived clients are not found. The answer carries no description, dates or billing value.',
    )]
    #[UrlParam('id', 'string', 'Project UUID or project key such as `DEMO`.', example: 'DEMO')]
    #[Response(LookupExamples::PROJECT, 200, 'The project.')]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No selectable project has this UUID or key.')]
    public function show(string $project, ReferenceResolver $refs): ProjectResource
    {
        $found = $refs->selectableProject($project);

        if ($found === null) {
            abort(404, __('api.errors.not_found'));
        }

        return new ProjectResource($found);
    }
}
