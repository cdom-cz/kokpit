<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Canary;
use Tests\Support\RouteInventory;
use Tests\Support\RouteRules;

/*
 * AUD-02, D-04, D-06, D-11, D-12, R-12-01-1/2/3: the route inventory. Every route of the router, vendor and
 * framework routes included, either carries a recognised authentication middleware or has exactly one entry
 * in tests/Support/RouteRules.php: the public allowlist (key, means, reason) or the closed list (key, expected
 * status, reason). A route with neither fails the suite, so a new route, in the application or in a package,
 * fails the build until it is justified in a pull request diff. An entry without a route, an entry in both
 * lists, an entry for a route that is in fact authenticated and an entry without a reason fail as well.
 * Every route is checked on every run, with no sampling.
 */

it('gives every route an auth middleware or exactly one RouteRules entry, and every entry a route', function (): void {
    $problems = RouteInventory::problems(RouteInventory::routes(), RouteRules::publicRoutes(), RouteRules::closedRoutes());

    expect($problems)->toBe([], implode("\n", $problems));
});

it('declares {0} with a known means and a non-empty reason', function (string $key): void {
    $public = RouteRules::publicRoutes();
    $closed = RouteRules::closedRoutes();

    expect(RouteRules::problemsIn(
        array_intersect_key($public, [$key => true]),
        array_intersect_key($closed, [$key => true]),
    ))->toBe([]);
})->with(array_map(
    static fn (string $key): array => [$key],
    [...array_keys(RouteRules::publicRoutes()), ...array_keys(RouteRules::closedRoutes())],
));

it('does not pass vacuously', function (): void {
    $keys = array_map(RouteInventory::key(...), RouteInventory::routes());
    $authenticated = array_map(
        RouteInventory::key(...),
        array_filter(RouteInventory::routes(), RouteInventory::isAuthenticated(...)),
    );

    expect($authenticated)->toContain('filament.admin.pages.dashboard')
        ->and($authenticated)->toContain('api.v1.time-entries.index')
        ->and(array_filter($authenticated, static fn (string $key): bool => str_starts_with($key, 'horizon.')))->not->toBeEmpty()
        ->and($keys)->toContain('pay.show')
        ->and($keys)->toContain('webhooks.stripe')
        ->and(in_array('default-livewire.update', $keys, true) || array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'POST /livewire-{hash}')) !== [])->toBeTrue();
});

it('reports a route without an auth middleware and without an entry', function (): void {
    $bare = Route::get('audit-probe-bare', static fn (): string => 'x');
    $guarded = Route::middleware('auth:sanctum')->get('audit-probe-guarded', static fn (): string => 'x');

    $problems = RouteInventory::problems(RouteInventory::routes(), RouteRules::publicRoutes(), RouteRules::closedRoutes());

    expect($problems)->toContain(RouteInventory::key($bare).' has no auth middleware and no RouteRules entry')
        ->and(implode("\n", $problems))->not->toContain(RouteInventory::key($guarded));
});

it('recognises an authentication middleware exactly, not by prefix', function (): void {
    $session = Route::middleware('Filament\Http\Middleware\AuthenticateSession')->get('audit-probe-session', static fn (): string => 'x');
    $basic = Route::middleware('auth.basic')->get('audit-probe-basic', static fn (): string => 'x');
    $filament = Route::middleware('Filament\Http\Middleware\Authenticate')->get('audit-probe-filament', static fn (): string => 'x');
    $sanctum = Route::middleware('auth:sanctum')->get('audit-probe-sanctum', static fn (): string => 'x');

    expect(RouteInventory::isAuthenticated($session))->toBeFalse()
        ->and(RouteInventory::isAuthenticated($basic))->toBeFalse()
        ->and(RouteInventory::isAuthenticated($filament))->toBeTrue()
        ->and(RouteInventory::isAuthenticated($sanctum))->toBeTrue();
});

it('reports a declared entry without a route, a key in both lists and a stale entry for an authenticated route', function (): void {
    $routes = RouteInventory::routes();
    $public = RouteRules::publicRoutes();
    $closed = RouteRules::closedRoutes();

    $withGhost = RouteInventory::problems($routes, [...$public, 'audit.ghost' => ['means' => 'none', 'reason' => 'planted']], $closed);
    $inBoth = RouteInventory::problems($routes, $public, [...$closed, 'pay.show' => ['status' => 404, 'reason' => 'planted']]);
    $stale = RouteInventory::problems($routes, [...$public, 'filament.admin.pages.dashboard' => ['means' => 'none', 'reason' => 'planted']], $closed);

    expect($withGhost)->toBe(['audit.ghost is declared in RouteRules but no such route exists'])
        ->and($inBoth)->toContain('pay.show is declared both public and closed in RouteRules')
        ->and($stale)->toBe(['filament.admin.pages.dashboard is authenticated; remove the stale RouteRules entry']);
});

it('rejects an empty reason, a whitespace reason, an unknown means and an unknown closed status', function (): void {
    expect(RouteRules::problemsIn(['a' => ['means' => 'none', 'reason' => '']], []))->toHaveCount(1)
        ->and(RouteRules::problemsIn(['a' => ['means' => 'none', 'reason' => " \t\n"]], []))->toHaveCount(1)
        ->and(RouteRules::problemsIn(['a' => ['means' => 'magic', 'reason' => 'planted']], []))->toHaveCount(1)
        ->and(RouteRules::problemsIn([], ['a' => ['status' => 200, 'reason' => 'planted']]))->toHaveCount(1)
        ->and(RouteRules::problemsIn([], ['a' => ['status' => 410, 'reason' => 'planted']]))->toBe([])
        ->and(RouteRules::problems())->toBe([]);
});

it('runs against the pinned test environment', function (): void {
    $keys = array_map(RouteInventory::key(...), RouteInventory::routes());

    expect(config('kokpit.canary_harness'))->toBeTrue()
        ->and(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'debugbar') || str_starts_with($key, 'GET /_debugbar')))->toBe([]);
});

it('keeps the two Phase 11 public routes declared with their means', function (): void {
    $public = RouteRules::publicRoutes();

    expect($public['pay.show']['means'])->toBe('token')
        ->and($public['webhooks.stripe']['means'])->toBe('signature');
});

/**
 * Every route under api/v1/.
 *
 * @return list<Illuminate\Routing\Route>
 */
function auditApiRoutes(): array
{
    return array_values(array_filter(
        RouteInventory::routes(),
        static fn (Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'api/v1/'),
    ));
}

/**
 * An anonymous-style request with no header at all (R-12-02-1): never ->json(), which hides defect 1.
 */
function auditRequest(Illuminate\Routing\Route $route): TestResponse
{
    Auth::forgetGuards();

    return test()->call(RouteInventory::method($route), RouteInventory::path($route));
}

it('defect 1: answers 401 JSON to a guest on every /api/v1 route that sends no Accept header', function (): void {
    $routes = auditApiRoutes();
    $offenders = [];

    foreach ($routes as $route) {
        $response = auditRequest($route);

        if ($response->getStatusCode() !== 401 || ! str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $offenders[] = RouteInventory::key($route).' '.RouteInventory::method($route).' '.RouteInventory::path($route)
                .' answered '.$response->getStatusCode().' '.$response->headers->get('Content-Type');
        }
    }

    expect($routes)->not->toBeEmpty()
        ->and($offenders)->toBe([], implode("\n", $offenders));
});

it('defect 1: answers 401 to a signed-in Admin and Partner session on every /api/v1 route that sends no Accept header', function (): void {
    [$clientId] = Canary::twoClients();
    $routes = auditApiRoutes();
    $offenders = [];

    foreach ([Canary::admin(), Canary::partnerFor($clientId)] as $user) {
        foreach ($routes as $route) {
            Auth::forgetGuards();
            $response = test()->actingAs($user)->call(RouteInventory::method($route), RouteInventory::path($route));

            if ($response->getStatusCode() !== 401) {
                $offenders[] = RouteInventory::key($route).' as user '.$user->getKey().' answered '.$response->getStatusCode();
            }
        }
    }

    expect($routes)->not->toBeEmpty()
        ->and($offenders)->toBe([], implode("\n", $offenders));
});

it('keeps the Filament login redirect and the Horizon 403 for a guest', function (): void {
    Auth::forgetGuards();
    $dashboard = test()->get('/admin');

    Auth::forgetGuards();
    $horizon = test()->get('/horizon');

    expect($dashboard->getStatusCode())->toBeIn([301, 302, 303, 307, 308])
        ->and((string) $dashboard->headers->get('Location'))->toEndWith('/admin/login')
        ->and($horizon->getStatusCode())->toBe(403);
});

it('defect 2: closes the Filament export and import download routes with 404 for a guest, a Partner and an Admin', function (): void {
    [$clientId] = Canary::twoClients();
    $paths = [
        '/filament/exports/'.Str::uuid().'/download',
        '/filament/imports/'.Str::uuid().'/failed-rows/download',
    ];
    $offenders = [];

    foreach ([null, Canary::partnerFor($clientId), Canary::admin()] as $user) {
        foreach ($paths as $path) {
            Auth::forgetGuards();
            $response = $user === null ? test()->get($path) : test()->actingAs($user)->get($path);

            if ($response->getStatusCode() !== 404) {
                $offenders[] = $path.' as '.($user === null ? 'guest' : $user->getKey()).' answered '.$response->getStatusCode();
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders))
        ->and(Schema::hasTable('exports'))->toBeFalse()
        ->and(Schema::hasTable('imports'))->toBeFalse();
});
