<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ReferenceResolver;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\ApiAbility;
use App\Http\Requests\Api\V1\ListLookupsRequest;
use App\Http\Resources\Api\V1\ClientResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\UrlParam;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Read-only lookup of clients (D-12): the clients an importer may record time for, found by name or UUID.
 *
 * Only non-archived clients answer. The resource carries the id and the name and nothing else: a client holds
 * rates, payment terms, tax data and invoice contact data that no lookup may reveal (T-07-36).
 */
#[Group('Lookups')]
#[Response(ApiExamples::UNAUTHENTICATED, 401, 'The bearer token is missing, unknown, revoked or expired, or its owner is not an active Admin.')]
#[Response(ApiExamples::FORBIDDEN, 403, 'The token does not carry the ability this endpoint needs.')]
#[Response(ApiExamples::TOO_MANY_REQUESTS, 429, 'Too many requests for this token. Wait for the number of seconds in the Retry-After header; X-RateLimit-Limit and X-RateLimit-Remaining show the limit and what is left.')]
final class ClientController
{
    /**
     * The filters of the list, `filter[<name>]`. The query-builder allow-list, the request rules and the
     * generated documentation all read this one list.
     */
    public const array FILTERS = ['name'];

    /**
     * The columns the list can be sorted by; a leading `-` sorts descending.
     */
    public const array SORTS = ['name', 'created_at'];

    /**
     * The tiebreaker of every sort and a key of every cursor of the list.
     */
    public const string ID_COLUMN = 'clients.id';

    #[Endpoint(
        'List clients',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns the clients an entry can be recorded for (archived clients are left out), newest first, a page at a time. Follow `meta.next_cursor` with the `cursor` parameter until it is `null`. A client carries its UUID and its name only.',
    )]
    #[QueryParam('filter[name]', 'string', 'Only clients whose name contains this text, ignoring case. A comma, a percent sign or an underscore is matched literally. A name finds a client; it never identifies one for a write, use the UUID.', required: false, example: 'fikt')]
    #[QueryParam('cursor', 'string', 'The `meta.next_cursor` of the previous page of this list, sent with the same `sort`. Any other value answers 422.', required: false, example: 'No-example')]
    #[QueryParam('per_page', 'integer', 'Clients per page, 1 to 200. Default 50.', required: false, example: 50)]
    #[QueryParam('sort', 'string', 'Sort by `created_at` (default, newest first as `-created_at`) or `name`; a leading `-` sorts descending.', required: false, example: '-created_at', enum: ['created_at', '-created_at', 'name', '-name'])]
    #[Response(LookupExamples::CLIENT_LIST, 200, 'A page of clients.')]
    #[Response(ApiExamples::VALIDATION_ERROR, 422, 'A filter, the page size, the cursor or the sort is not valid.')]
    public function index(ListLookupsRequest $request): AnonymousResourceCollection
    {
        $descending = str_starts_with($request->sortExpression(), '-');

        $clients = QueryBuilder::for(Client::query())
            ->allowedFilters(AllowedFilter::partial('name'))
            ->allowedSorts(...self::SORTS)
            ->defaultSort('-created_at')
            // The id breaks ties between clients that share the sorted value, in the direction of the sort,
            // so a cursor page never skips or repeats a row.
            ->orderBy(self::ID_COLUMN, $descending ? 'desc' : 'asc')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ClientResource::collection($clients);
    }

    #[Endpoint(
        'Show a client',
        'Requires ability `'.ApiAbility::TimeRead->value.'`. Returns one client by its UUID. An archived client is not found. The answer carries the UUID and the name only.',
    )]
    #[UrlParam('id', 'string', 'Client UUID.', example: '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9')]
    #[Response(LookupExamples::CLIENT, 200, 'The client.')]
    #[Response(ApiExamples::NOT_FOUND, 404, 'No selectable client has this UUID.')]
    public function show(string $client, ReferenceResolver $refs): ClientResource
    {
        $found = $refs->selectableClient($client);

        if ($found === null) {
            abort(404, __('api.errors.not_found'));
        }

        return new ClientResource($found);
    }
}
