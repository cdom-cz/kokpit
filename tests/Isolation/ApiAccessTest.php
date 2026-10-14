<?php

declare(strict_types=1);

use App\Domain\Identity\ApiAbility;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;

/*
 * D-01, D-16, T-07-56: a route walk over every /api/v1 route. Everything is derived from the
 * route collection, so a route added by a later plan is walked without editing this file: it
 * needs a token (auth:sanctum), exactly one ability and the rate limit of that ability, and it
 * answers 401 to a guest and to a Partner token and 403 to an Admin token without its ability.
 */

/**
 * Every route under api/v1/.
 *
 * @return list<LaravelRoute>
 */
function apiWalkRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/')) {
            $routes[] = $route;
        }
    }

    return $routes;
}

/**
 * The middleware strings of a route that start with the prefix.
 *
 * @return list<string>
 */
function apiWalkMiddleware(LaravelRoute $route, string $prefix): array
{
    return array_values(array_filter(
        array_map('strval', $route->gatherMiddleware()),
        static fn (string $name): bool => str_starts_with($name, $prefix),
    ));
}

/**
 * The ability a route demands, from its single abilities: middleware.
 */
function apiWalkAbility(LaravelRoute $route): string
{
    return substr(apiWalkMiddleware($route, 'abilities:')[0], strlen('abilities:'));
}

/**
 * The request path of a route with every path parameter replaced by a fresh uuid.
 */
function apiWalkPath(LaravelRoute $route): string
{
    return '/'.preg_replace_callback('/\{[^}]+\}/', static fn (): string => (string) Str::uuid(), $route->uri());
}

function apiWalkMethod(LaravelRoute $route): string
{
    return array_values(array_diff($route->methods(), ['HEAD']))[0];
}

it('finds at least one api/v1 route, so the walk is not vacuous', function (): void {
    expect(apiWalkRoutes())->not->toBeEmpty();
});

/**
 * What is wrong with the middleware of the routes: no token guard, not exactly one ability,
 * an unknown ability, or not exactly the rate limit of the ability.
 *
 * @param  list<LaravelRoute>  $routes
 * @return list<string>
 */
function apiWalkProblems(array $routes): array
{
    $limits = [ApiAbility::TimeRead->value => 'throttle:api-read', ApiAbility::TimeWrite->value => 'throttle:api-write'];
    $problems = [];

    foreach ($routes as $route) {
        $label = apiWalkMethod($route).' '.$route->uri();
        $abilities = apiWalkMiddleware($route, 'abilities:');

        if (! in_array('auth:sanctum', array_map('strval', $route->gatherMiddleware()), true)) {
            $problems[] = "{$label} has no auth:sanctum";
        }

        if (count($abilities) !== 1) {
            $problems[] = "{$label} needs exactly one abilities: middleware";

            continue;
        }

        $ability = apiWalkAbility($route);

        if (! array_key_exists($ability, $limits)) {
            $problems[] = "{$label} names the unknown ability {$ability}";
        } elseif (apiWalkMiddleware($route, 'throttle:') !== [$limits[$ability]]) {
            $problems[] = "{$label} needs exactly {$limits[$ability]}";
        }
    }

    return $problems;
}

it('guards every api/v1 route with auth:sanctum, exactly one ability and the matching rate limit', function (): void {
    expect(apiWalkProblems(apiWalkRoutes()))->toBe([]);
});

it('reports a route without a token guard, an ability or a rate limit, so the walk cannot pass blindly', function (): void {
    $bare = Route::get('api/v1/probe-bare', static fn (): string => 'x');
    $noLimit = Route::middleware(['auth:sanctum', 'abilities:time:read'])->get('api/v1/probe-no-limit', static fn (): string => 'x');
    $twoAbilities = Route::middleware(['auth:sanctum', 'abilities:time:read', 'abilities:time:write', 'throttle:api-read'])->get('api/v1/probe-two', static fn (): string => 'x');
    $wrongLimit = Route::middleware(['auth:sanctum', 'abilities:time:write', 'throttle:api-read'])->get('api/v1/probe-wrong-limit', static fn (): string => 'x');
    $guarded = Route::middleware(['auth:sanctum', 'abilities:time:read', 'throttle:api-read'])->get('api/v1/probe-guarded', static fn (): string => 'x');

    expect(apiWalkProblems([$bare]))->toHaveCount(2)
        ->and(apiWalkProblems([$noLimit]))->toHaveCount(1)
        ->and(apiWalkProblems([$twoAbilities]))->toHaveCount(1)
        ->and(apiWalkProblems([$wrongLimit]))->toHaveCount(1)
        ->and(apiWalkProblems([$guarded]))->toBe([]);
});

it('answers 401 to a guest on every api/v1 route', function (): void {
    foreach (apiWalkRoutes() as $route) {
        $this->json(apiWalkMethod($route), apiWalkPath($route))
            ->assertUnauthorized();
    }
});

it('answers 401 to the token of a Partner on every api/v1 route', function (): void {
    $partner = Canary::partnerFor(ApiFixtures::client()->id);
    $token = ApiFixtures::token($partner, ApiAbility::values());

    foreach (apiWalkRoutes() as $route) {
        Auth::forgetGuards();

        $this->withToken($token)->json(apiWalkMethod($route), apiWalkPath($route))
            ->assertUnauthorized();
    }
});

it('answers 403 to an Admin token that lacks the ability of the route', function (): void {
    $admin = ApiFixtures::admin();

    foreach (apiWalkRoutes() as $route) {
        $ability = apiWalkAbility($route);
        $token = ApiFixtures::token($admin, array_values(array_diff(ApiAbility::values(), [$ability])));
        Auth::forgetGuards();

        $this->withToken($token)->json(apiWalkMethod($route), apiWalkPath($route))
            ->assertForbidden();
    }
});
