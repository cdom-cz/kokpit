<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ApiDocs;

/*
 * AP-05, D-17: the API documentation is generated from the code and must describe every /api/v1 route.
 * The rules walk the route table (ApiDocs::violations), so a route added by a later plan fails this file
 * until it carries its Scribe attributes; nothing here names a specific endpoint.
 */

beforeEach(function (): void {
    ApiDocs::ensureGenerated();
});

it('generates without a database and documents the placeholder base URL as OpenAPI 3.1', function (): void {
    $spec = ApiDocs::spec();

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['info']['title'])->toBe('Kokpit API')
        ->and($spec['servers'][0]['url'])->toBe(ApiDocs::placeholderUrl());
});

it('generates with exactly the command the deploy manifest runs last on every container', function (): void {
    $manifest = Yaml::parseFile(base_path('zerops.yml'));
    $init = $manifest['zerops'][0]['run']['initCommands'];

    expect(explode(' ', (string) end($init)))->toBe(ApiDocs::COMMAND)
        ->and(ApiDocs::generate()->getExitCode())->toBe(0);
});

it('declares a bearer scheme that the global security references', function (): void {
    $spec = ApiDocs::spec();
    $schemes = $spec['components']['securitySchemes'];

    $bearer = array_filter($schemes, static fn (array $scheme): bool => $scheme['type'] === 'http' && $scheme['scheme'] === 'bearer');

    expect($bearer)->not->toBeEmpty();

    $referenced = [];
    foreach ($spec['security'] as $requirement) {
        $referenced = [...$referenced, ...array_keys($requirement)];
    }

    expect(array_intersect($referenced, array_keys($bearer)))->not->toBeEmpty();
});

it('documents every api/v1 route completely', function (): void {
    $routes = ApiDocs::apiRoutes();

    expect($routes)->not->toBeEmpty()
        ->and(ApiDocs::violations($routes, ApiDocs::spec()))->toBe([]);
});

it('gives every documented operation its 401, 403 and 429 responses and names its ability', function (): void {
    foreach (ApiDocs::apiRoutes() as $route) {
        $operation = ApiDocs::operation($route);

        expect($operation)->not->toBeNull()
            ->and($operation['responses'])->toHaveKeys([401, 403, 429])
            ->and($operation['description'])->toMatch('/`[a-z]+:[a-z]+`/');
    }
});

it('fails the rules for a route that has no attributes (the check can fail)', function (): void {
    $probe = Route::middleware(['abilities:time:read'])->get('api/v1/probe-undocumented/{id}', static fn (): string => 'x');
    $routes = [...ApiDocs::apiRoutes(), $probe];

    $violations = ApiDocs::violations($routes, ApiDocs::spec());

    expect($violations)->toContain('GET /api/v1/probe-undocumented/{id} is not in the spec');
});

it('fails the rules for an operation without its ability, a missing status or a missing query parameter', function (): void {
    $route = ApiDocs::apiRoutes()[0];
    $spec = ApiDocs::spec();
    $path = '/'.$route->uri();
    $method = strtolower(ApiDocs::method($route));

    $withoutAbility = $spec;
    $withoutAbility['paths'][$path][$method]['description'] = 'Returns one time entry.';
    expect(ApiDocs::violations([$route], $withoutAbility))->not->toBeEmpty();

    $withoutRateLimit = $spec;
    unset($withoutRateLimit['paths'][$path][$method]['responses'][429]);
    expect(ApiDocs::violations([$route], $withoutRateLimit))->toContain('GET /'.$route->uri().' documents no 429 response');
});

it('requires the query parameters a list controller declares and the Idempotency-Key header', function (): void {
    $filtered = new class
    {
        public const array FILTERS = ['name'];

        public const array SORTS = ['started_at'];

        public function index(): void {}
    };
    $route = Route::middleware(['abilities:time:read', 'EnsureIdempotency'])->get('api/v1/probe-list', [$filtered::class, 'index']);
    $spec = [
        'paths' => ['/api/v1/probe-list' => ['get' => [
            'description' => 'Requires ability `time:read`.',
            'parameters' => [],
            'responses' => [200 => [], 401 => [], 403 => [], 422 => [], 429 => [], 409 => []],
        ]]],
    ];

    expect(ApiDocs::violations([$route], $spec))->toBe([
        'GET /api/v1/probe-list documents no query parameter cursor',
        'GET /api/v1/probe-list documents no query parameter per_page',
        'GET /api/v1/probe-list documents no query parameter filter[name]',
        'GET /api/v1/probe-list documents no query parameter sort',
        'GET /api/v1/probe-list documents no Idempotency-Key header',
    ]);

    $spec['paths']['/api/v1/probe-list']['get']['parameters'] = [
        ['in' => 'query', 'name' => 'cursor'],
        ['in' => 'query', 'name' => 'per_page'],
        ['in' => 'query', 'name' => 'filter[name]'],
        ['in' => 'query', 'name' => 'sort'],
        ['in' => 'header', 'name' => 'Idempotency-Key'],
    ];

    expect(ApiDocs::violations([$route], $spec))->toBe([]);
});

it('publishes the page assets and no Try It Out script', function (): void {
    expect(glob(public_path('vendor/scribe/css/*')))->not->toBeEmpty()
        ->and(glob(public_path('vendor/scribe/js/*')))->not->toBeEmpty()
        ->and(strtolower(ApiDocs::view()))->not->toContain('tryitout');
});

it('keeps the generated output free of a developer host', function (): void {
    $forbidden = [implode('', ['.ddev', '.site']), implode('', ['local', 'host'])];
    $spec = (string) file_get_contents(ApiDocs::specPath());

    foreach ($forbidden as $needle) {
        expect($spec)->not->toContain($needle);
    }
});

it('stores the generated spec on a local disk under storage, not on a shared volume', function (): void {
    expect(config('filesystems.disks.local.driver'))->toBe('local')
        ->and(config('filesystems.disks.local.root'))->toStartWith(storage_path());
});

it('pins the routes of the API, no more and no fewer', function (): void {
    $actual = array_map(
        fn ($route): string => ApiDocs::method($route).' /'.$route->uri(),
        ApiDocs::apiRoutes(),
    );
    sort($actual);

    $expected = [
        'GET /api/v1/clients',
        'GET /api/v1/clients/{client}',
        'GET /api/v1/projects',
        'GET /api/v1/projects/{project}',
        'GET /api/v1/tasks',
        'GET /api/v1/tasks/{task}',
        'GET /api/v1/time-entries',
        'GET /api/v1/time-entries/{timeEntry}',
        'GET /api/v1/transaction-categories',
        'GET /api/v1/transaction-categories/{category}',
        'GET /api/v1/transactions',
        'GET /api/v1/transactions/{transaction}',
        'POST /api/v1/time-entries',
        'POST /api/v1/time-entries/batch',
        'PATCH /api/v1/time-entries/{timeEntry}',
        'DELETE /api/v1/time-entries/{timeEntry}',
    ];
    sort($expected);

    expect($actual)->toBe($expected);
});

it('describes every pinned route in the published spec with its method', function (): void {
    foreach (ApiDocs::apiRoutes() as $route) {
        expect(ApiDocs::operation($route))->not->toBeNull('no operation for '.ApiDocs::method($route).' /'.$route->uri());
    }
});

it('documents the Idempotency-Key on exactly the two create operations', function (): void {
    $withKey = [];

    foreach (ApiDocs::spec()['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['parameters'] ?? [] as $parameter) {
                if (($parameter['in'] ?? null) === 'header' && strtolower((string) ($parameter['name'] ?? '')) === 'idempotency-key') {
                    $withKey[] = strtoupper($method).' '.$path;
                }
            }
        }
    }
    sort($withKey);

    expect($withKey)->toBe(['POST /api/v1/time-entries', 'POST /api/v1/time-entries/batch']);
});

it('documents 201, 207, 409 and 422 on the batch operation', function (): void {
    $route = Route::getRoutes()->getByName('api.v1.time-entries.batch');
    $operation = ApiDocs::operation($route);

    expect($operation)->not->toBeNull()
        ->and($operation['responses'])->toHaveKeys([201, 207, 409, 422]);
});

it('fails the generation, with exit status 2, when one route attribute is broken, and a normal run afterwards is clean', function (): void {
    try {
        $broken = ApiDocs::generateWithBrokenRoute();

        expect($broken->getExitCode())->toBe(2)
            ->and($broken->getOutput().$broken->getErrorOutput())->toContain('_docs-probe');
    } finally {
        $clean = ApiDocs::generate();
    }

    $paths = array_keys(ApiDocs::spec()['paths']);

    expect($clean->getExitCode())->toBe(0)
        ->and(array_filter($paths, fn (string $path): bool => str_contains($path, '_docs-probe')))->toBe([])
        ->and(ApiDocs::view())->not->toContain('_docs-probe');
});

it('describes validation rules in English, with no raw translation placeholder left in the page or the spec', function (): void {
    $spec = (string) file_get_contents(ApiDocs::specPath());
    $page = ApiDocs::view();

    // Scribe appends one sentence per validation rule it can describe; a translation line it cannot fill leaves a
    // placeholder such as :Attribute or :max in the output.
    foreach (['attribute', 'max', 'min', 'value', 'values', 'other', 'size', 'digits', 'format', 'date'] as $placeholder) {
        expect(preg_match('/(?<![A-Za-z0-9]):'.$placeholder.'\b/i', $spec))->toBe(0, "the spec holds :{$placeholder}")
            ->and(preg_match('/(?<![A-Za-z0-9]):'.$placeholder.'\b/i', $page))->toBe(0, "the page holds :{$placeholder}");
    }

    $operation = ApiDocs::operation(Route::getRoutes()->getByName('api.v1.time-entries.store'));
    $properties = $operation['requestBody']['content']['application/json']['schema']['properties'];

    expect($properties['duration_seconds']['description'])->toContain('Must be at least 1.')
        ->and($properties['duration_seconds']['description'])->toContain('Must not be greater than 2147483647.');
});

/**
 * The problems of the limits the public docs state, compared with the `kokpit.api` configuration (IN-02).
 *
 * Every sentence of the intro that names a number is matched and its number compared with the configured one;
 * a sentence that cannot be found is a problem too, so a reworded intro cannot silently skip the check.
 *
 * @param  list<string>  $perPageDescriptions  the description of the per_page parameter of every list operation
 * @param  array<string, mixed>  $api  the `kokpit.api` configuration array
 * @return list<string>
 */
function documentedLimitProblems(string $intro, array $perPageDescriptions, array $api): array
{
    $configured = static function (string $path) use ($api): int {
        $value = data_get($api, $path);
        expect($value)->toBeInt();

        return (int) $value;
    };

    $sentences = [
        'kokpit.api.rate_limit.read' => '/(\d+) read requests/',
        'kokpit.api.rate_limit.write' => '/(\d+) write requests/',
        'kokpit.api.page_size.default' => '/A page holds (\d+) items/',
        'kokpit.api.page_size.max' => '/`per_page` \(1 to (\d+)\)/',
        'kokpit.api.batch_max_items' => '/records up to (\d+) entries/',
        'kokpit.api.idempotency_ttl_hours' => '/kept for (\d+) hours/',
    ];
    $problems = [];

    foreach ($sentences as $key => $pattern) {
        $expected = $configured(substr($key, strlen('kokpit.api.')));

        if (preg_match($pattern, $intro, $found) !== 1) {
            $problems[] = "the intro has no sentence for {$key}";
        } elseif ((int) $found[1] !== $expected) {
            $problems[] = "{$key} is {$expected} but the intro says {$found[1]}";
        }
    }

    // The batch limit is stated a second time, for the list that is refused.
    if (preg_match('/more than (\d+), answers `422`/', $intro, $found) !== 1) {
        $problems[] = 'the intro has no refusal sentence for kokpit.api.batch_max_items';
    } elseif ((int) $found[1] !== $configured('batch_max_items')) {
        $problems[] = "kokpit.api.batch_max_items is {$configured('batch_max_items')} but the intro refuses at {$found[1]}";
    }

    foreach ($perPageDescriptions as $description) {
        if (! str_contains($description, '1 to '.$configured('page_size.max').'.')) {
            $problems[] = "kokpit.api.page_size.max is {$configured('page_size.max')} but a per_page description says: {$description}";
        }

        if (! str_contains($description, 'Default '.$configured('page_size.default').'.')) {
            $problems[] = "kokpit.api.page_size.default is {$configured('page_size.default')} but a per_page description says: {$description}";
        }
    }

    return $problems;
}

/**
 * The per_page descriptions of every documented GET list operation of the spec.
 *
 * @return list<string>
 */
function documentedPerPageDescriptions(): array
{
    $descriptions = [];

    foreach (ApiDocs::apiRoutes() as $route) {
        $operation = ApiDocs::method($route) === 'GET' ? ApiDocs::operation($route) : null;

        foreach (is_array($operation) ? ($operation['parameters'] ?? []) : [] as $parameter) {
            if (is_array($parameter) && ($parameter['name'] ?? null) === 'per_page' && is_string($parameter['description'] ?? null)) {
                $descriptions[] = $parameter['description'];
            }
        }
    }

    return $descriptions;
}

it('states exactly the limits of config/kokpit.php in the intro and in every per_page description (IN-02)', function (): void {
    $intro = config('scribe.intro_text');
    $descriptions = documentedPerPageDescriptions();

    expect($intro)->toBeString()
        ->and($descriptions)->toHaveCount(6)
        ->and(documentedLimitProblems((string) $intro, $descriptions, (array) config('kokpit.api')))->toBe([]);
});

it('reports a documented limit that differs from the configuration (the limits check can fail)', function (): void {
    $intro = (string) config('scribe.intro_text');
    $descriptions = documentedPerPageDescriptions();
    $api = (array) config('kokpit.api');

    $readChanged = $api;
    $readChanged['rate_limit']['read'] = 121;

    $maxChanged = $api;
    $maxChanged['page_size']['max'] = 201;

    $batchChanged = $api;
    $batchChanged['batch_max_items'] = 101;

    expect(documentedLimitProblems($intro, $descriptions, $readChanged))->toBe(['kokpit.api.rate_limit.read is 121 but the intro says 120'])
        ->and(implode("\n", documentedLimitProblems($intro, $descriptions, $maxChanged)))->toContain('kokpit.api.page_size.max is 201')
        ->and(implode("\n", documentedLimitProblems($intro, $descriptions, $batchChanged)))->toContain('kokpit.api.batch_max_items is 101')
        ->and(documentedLimitProblems('A reworded intro.', $descriptions, $api))->toContain('the intro has no sentence for kokpit.api.rate_limit.read');
});
