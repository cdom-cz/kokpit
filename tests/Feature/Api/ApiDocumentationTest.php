<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
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
