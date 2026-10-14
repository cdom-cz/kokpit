<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Identity\ApiAbility;
use App\Http\Resources\Api\V1\TaskResource;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\UrlParam;

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
