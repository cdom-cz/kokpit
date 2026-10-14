<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureApiDocsGenerated;
use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\removeStrategies;

// Only the most common configs are shown. See the https://scribe.knuckles.wtf/laravel/reference/config for all.

return [
    // The HTML <title> for the generated documentation.
    'title' => 'Kokpit API',

    // A short description of your API. Will be included in the docs webpage, Postman collection and OpenAPI spec.
    'description' => 'REST API for reading and recording time entries, and for looking up clients, projects and tasks.',

    // Text to place in the "Introduction" section, right after the `description`. Markdown and HTML are supported.
    'intro_text' => <<<'INTRO'
            The Kokpit API is a JSON API under `/api/v1`. Every endpoint needs a bearer token and answers JSON.

            ## Abilities

            A token carries one or both abilities. A request whose token lacks the ability of the endpoint is refused with `403`.

            - `time:read`: read time entries and look up clients, projects and tasks (GET endpoints).
            - `time:write`: create, change and delete time entries (POST, PATCH and DELETE endpoints).

            ## Rate limits

            Each token may send 120 read requests and 60 write requests a minute. Over the limit the API answers `429` with a `Retry-After` header; every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`.

            ## Lists and pagination

            List endpoints are cursor paginated and answer `{"data": [...], "links": {...}, "meta": {...}}`. A page holds 50 items; ask for another size with `per_page` (1 to 200). To read the next page send the `meta.next_cursor` of the previous answer as `cursor`; when it is `null` there is no further page. The time entry list is newest first by start and breaks ties by id, so paging never skips or repeats an entry.

            ## Formats and errors

            - Instants are ISO 8601 with an offset, for example `2026-10-12T08:00:00+00:00`. Responses are in UTC.
            - Every key is always present. A value that does not exist is `null`: for example `ended_at` and `duration_seconds` of a running time entry, or `project_id` of an entry that has only a client.
            - A validation error answers `422` with `{"message": "...", "errors": {"field": ["..."]}}`.
            - A conflict (for example a locked or running entry) answers `409` with `{"message": "...", "code": "..."}`.

            ## Retrying a create safely: the Idempotency-Key

            The two create endpoints, `POST /api/v1/time-entries` and `POST /api/v1/time-entries/batch`, accept an optional `Idempotency-Key` header, so a request that timed out can be sent again without creating a second entry. PATCH, DELETE and GET ignore it.

            - Send 1 to 255 visible characters, a fresh value for every new request (a UUID works well). Keys belong to the token that sent them and are kept for 24 hours.
            - The same key with the same body does not create anything a second time: the answer stored for the first request is sent again, byte for byte, with the response header `Idempotent-Replayed: true`.
            - Only successful answers (`201`, and `207` of a batch) are stored. A request that failed leaves its key free, so the corrected request can be sent again with the same key.
            - While the first request with a key is still running, a repeat answers `409` with the code `idempotency_in_progress`; wait a moment and send it again.
            - The same key with a different body answers `422` with the code `idempotency_key_reused`; a malformed key answers `422` with the code `idempotency_key_invalid`.
            - A request without the header is never deduplicated.

            ## Batches

            `POST /api/v1/time-entries/batch` records up to 100 entries in one request. Each item takes the fields of a single create and is saved on its own, through the same rules and in its own transaction, so one invalid item never blocks the others.

            - The answer is `{"saved": n, "failed": m, "errors": [{"index": i, "errors": {"field": ["..."]}}]}`. `errors` lists only the failed items, with their zero-based position in `entries`. An item that is not a JSON object fails under the field `entry`.
            - `201` means every item was saved, `207` that some were saved and some failed, `422` that none was saved. A list with no item, or with more than 100, answers `422` under `entries` and saves nothing.
            - A batch counts as one write against the rate limit of the token.
            - To retry a batch, send it again with the same `Idempotency-Key`: a stored `201` or `207` is replayed and nothing new is saved, and a `422` stored nothing, so the corrected batch can be sent with the same key. Do not resend only the failed items under the old key; that is a different body.
            - Keys protect only a retry of the same request. If a batch is sent again under a different key, or the server fails in the middle of a batch, items may already be saved: deduplicate on your side: store your own id in `external` (it comes back with every entry), and list the entries of the period before you resend.
            INTRO,

    // The base URL displayed in the docs.
    // If you're using `laravel` type, you can set this to a dynamic string, like '{{ config("app.tenant_url") }}' to get a dynamic base URL.
    'base_url' => config('app.url'),

    // Routes to include in the docs
    'routes' => [
        [
            'match' => [
                // Match only routes whose paths match this pattern (use * as a wildcard to match any characters). Example: 'users/*'.
                'prefixes' => ['api/v1/*'],

                // Match only routes whose domains match this pattern (use * as a wildcard to match any characters). Example: 'api.*'.
                'domains' => ['*'],
            ],

            // Include these routes even if they did not match the rules above.
            'include' => [
                // 'users.index', 'POST /new', '/auth/*'
            ],

            // Exclude these routes even if they matched the rules above.
            'exclude' => [
                // 'GET /health', 'admin.*'
            ],
        ],
    ],

    // The type of documentation output to generate.
    // - "static" will generate a static HTMl page in the /public/docs folder,
    // - "laravel" will generate the documentation as a Blade view, so you can add routing and authentication.
    // - "external_static" and "external_laravel" do the same as above, but pass the OpenAPI spec as a URL to an external UI template
    'type' => 'laravel',

    // See https://scribe.knuckles.wtf/laravel/reference/config#theme for supported options
    'theme' => 'default',

    'static' => [
        // HTML documentation, assets and Postman collection will be generated to this folder.
        // Source Markdown will still be in resources/docs.
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        // Whether to automatically create a docs route for you to view your generated docs. You can still set up routing manually.
        'add_routes' => true,

        // URL path to use for the docs endpoint (if `add_routes` is true).
        // By default, `/docs` opens the HTML page, `/docs.postman` opens the Postman collection, and `/docs.openapi` the OpenAPI spec.
        'docs_url' => '/docs',

        // Directory within `public` in which to store CSS and JS assets.
        // By default, assets are stored in `public/vendor/scribe`.
        // If set, assets will be stored in `public/{{assets_directory}}`
        'assets_directory' => null,

        // Middleware to attach to the docs endpoint (if `add_routes` is true).
        // Public by owner decision: no `web` (no session or cookie) and no `auth` (no `login` route exists,
        // so a guest would get a 500). The limiter is per IP address; the middleware answers 404 before the
        // first generation and sets the noindex header.
        'middleware' => ['throttle:api-docs', EnsureApiDocsGenerated::class],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        // Add a Try It Out button to your endpoints so consumers can test endpoints right from their browser.
        // Don't forget to enable CORS headers for your endpoints.
        // Off: the docs page is public, so it must never offer to send a typed token from the browser.
        'enabled' => false,

        // The base URL to use in the API tester. Leave as null to be the same as the displayed URL (`scribe.base_url`).
        'base_url' => null,

        // [Laravel Sanctum] Fetch a CSRF token before each request, and add it as an X-XSRF-TOKEN header.
        'use_csrf' => false,

        // The URL to fetch the CSRF token from (if `use_csrf` is true).
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // How is your API authenticated? This information will be used in the displayed docs, generated examples and response calls.
    'auth' => [
        // Set this to true if ANY endpoints in your API use authentication.
        'enabled' => true,

        // Set this to true if your API should be authenticated by default. If so, you must also set `enabled` (above) to true.
        // You can then use @unauthenticated or @authenticated on individual endpoints to change their status from the default.
        'default' => true,

        // Where is the auth value meant to be sent in a request?
        'in' => AuthIn::BEARER->value,

        // The name of the auth parameter (e.g. token, key, apiKey) or header (e.g. Authorization, Api-Key).
        'name' => 'Authorization',

        // The value of the parameter to be used by Scribe to authenticate response calls.
        // This will NOT be included in the generated documentation. If empty, Scribe will use a random value.
        // The placeholder, so Scribe's intermediate files never hold a random token-shaped value (the response
        // calls are removed anyway, see the responses strategies below).
        'use_value' => '{YOUR_API_TOKEN}',

        // Placeholder your users will see for the auth parameter in the example requests.
        // Set this to null if you want Scribe to use a random value as placeholder instead.
        'placeholder' => '{YOUR_API_TOKEN}',

        // Any extra authentication-related info for your users. Markdown and HTML are supported.
        'extra_info' => 'Create a token in the admin panel, on the screen <b>API tokeny</b>. The token is shown once, right after it is created, so store it then. Choose the abilities <code>time:read</code> and <code>time:write</code> the token needs.',
    ],

    // Example requests for each endpoint will be shown in each of these languages.
    // Supported options are: bash, javascript, php, python
    // To add a language of your own, see https://scribe.knuckles.wtf/laravel/advanced/example-requests
    // Note: does not work for `external` docs types
    'example_languages' => [
        'bash',
    ],

    // Generate a Postman collection (v2.1.0) in addition to HTML docs.
    // For 'static' docs, the collection will be generated to public/docs/collection.json.
    // For 'laravel' docs, it will be generated to storage/app/scribe/collection.json.
    // Setting `laravel.add_routes` to true (above) will also add a route for the collection.
    'postman' => [
        'enabled' => false,

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    // Generate an OpenAPI spec in addition to docs webpage.
    // For 'static' docs, the collection will be generated to public/docs/openapi.yaml.
    // For 'laravel' docs, it will be generated to storage/app/scribe/openapi.yaml.
    // Setting `laravel.add_routes` to true (above) will also add a route for the spec.
    'openapi' => [
        'enabled' => true,

        // The OpenAPI spec version to generate. Supported versions: '3.0.3', '3.1.0'.
        // OpenAPI 3.1 is more compatible with JSON Schema and is becoming the dominant version.
        // See https://spec.openapis.org/oas/v3.1.0 for details on 3.1 changes.
        'version' => '3.1.0',

        'overrides' => [
            'info.version' => '1.0.0',
        ],

        // Additional generators to use when generating the OpenAPI spec.
        // Should extend `Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator`.
        'generators' => [],
    ],

    'groups' => [
        // Endpoints which don't have a @group will be placed in this default group.
        'default' => 'Endpoints',

        // By default, Scribe will sort groups alphabetically, and endpoints in the order their routes are defined.
        // You can override this by listing the groups, subgroups and endpoints here in the order you want them.
        // See https://scribe.knuckles.wtf/blog/laravel-v4#easier-sorting and https://scribe.knuckles.wtf/laravel/reference/config#order for details
        // Note: does not work for `external` docs types
        'order' => [],
    ],

    // Custom logo path. This will be used as the value of the src attribute for the <img> tag,
    // so make sure it points to an accessible URL or path. Set to false to not use a logo.
    // For example, if your logo is in public/img:
    // - 'logo' => '../img/logo.png' // for `static` type (output folder is public/docs)
    // - 'logo' => 'img/logo.png' // for `laravel` type
    'logo' => false,

    // Customize the "Last updated" value displayed in the docs by specifying tokens and formats.
    // Examples:
    // - {date:F j Y} => March 28, 2022
    // - {git:short} => Short hash of the last Git commit
    // Available tokens are `{date:<format>}` and `{git:<format>}`.
    // The format you pass to `date` will be passed to PHP's `date()` function.
    // The format you pass to `git` can be either "short" or "long".
    // Note: does not work for `external` docs types
    'last_updated' => '',

    'examples' => [
        // Set this to any number to generate the same example values for parameters on each run,
        'faker_seed' => 1234,

        // With API resources and transformers, Scribe tries to generate example models to use in your API responses.
        // By default, Scribe will try the model's factory, and if that fails, try fetching the first from the database.
        // You can reorder or remove strategies here.
        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    // The strategies Scribe will use to extract information about your routes at each stage.
    // Use configureStrategy() to specify settings for a strategy in the list.
    // Use removeStrategies() to remove an included strategy.
    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        // No response calls: every response example is a fixed fictional #[Response] payload, so generation
        // never runs a route and never reads the database (the output is public).
        'responses' => removeStrategies(Defaults::RESPONSES_STRATEGIES, [Strategies\Responses\ResponseCalls::class]),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    // For response calls, API resource responses and transformer responses,
    // Scribe will try to start database transactions, so no changes are persisted to your database.
    // Tell Scribe which connections should be transacted here. If you only use one db connection, you can leave this as is.
    // None: no strategy touches the database, so nothing is transacted.
    'database_connections_to_transact' => [],

    'fractal' => [
        // If you are using a custom serializer with league/fractal, you can specify it here.
        'serializer' => null,
    ],
];
