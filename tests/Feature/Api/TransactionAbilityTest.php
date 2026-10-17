<?php

declare(strict_types=1);

use App\Domain\Identity\ApiAbility;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;

/*
 * D-01 in both directions: a token with only time abilities is refused (403) on every finance route and a token
 * with only finance abilities on every time route. The route sets are derived from the route table through the
 * value of the `abilities:` middleware, so a finance route added by a later plan is covered without editing
 * this file. Reads need finance:read, writes finance:write, and one never stands in for the other.
 */

/**
 * The routes under api/v1/ whose single ability starts with the prefix (`finance:` or `time:`).
 *
 * @return list<LaravelRoute>
 */
function financeAbilityRoutes(string $prefix, ?string $method = null): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $abilities = array_values(array_filter(
            array_map('strval', $route->gatherMiddleware()),
            static fn (string $name): bool => str_starts_with($name, 'abilities:'.$prefix),
        ));

        if ($abilities === []) {
            continue;
        }

        if ($method !== null && ! in_array($method, $route->methods(), true)) {
            continue;
        }

        $routes[] = $route;
    }

    return $routes;
}

function financeAbilityMethod(LaravelRoute $route): string
{
    return array_values(array_diff($route->methods(), ['HEAD']))[0];
}

/**
 * The request path of a route with every path parameter replaced by a fresh uuid.
 */
function financeAbilityPath(LaravelRoute $route): string
{
    return '/'.preg_replace_callback('/\{[^}]+\}/', static fn (): string => (string) Str::uuid(), $route->uri());
}

it('finds finance routes and time routes in the route table, so the walks below are not vacuous', function (): void {
    expect(financeAbilityRoutes('finance:'))->not->toBeEmpty()
        ->and(financeAbilityRoutes('time:'))->not->toBeEmpty();
});

it('answers 403 on every finance route to a token that holds only the time abilities', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), [ApiAbility::TimeRead->value, ApiAbility::TimeWrite->value]);

    foreach (financeAbilityRoutes('finance:') as $route) {
        Auth::forgetGuards();

        $this->withToken($token)->json(financeAbilityMethod($route), financeAbilityPath($route))
            ->assertForbidden();
    }
});

it('answers 403 on every time route to a token that holds only the finance abilities', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), [ApiAbility::FinanceRead->value, ApiAbility::FinanceWrite->value]);

    foreach (financeAbilityRoutes('time:') as $route) {
        Auth::forgetGuards();

        $this->withToken($token)->json(financeAbilityMethod($route), financeAbilityPath($route))
            ->assertForbidden();
    }
});

it('answers 403 on every finance read route to a token that holds only finance:write', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), [ApiAbility::FinanceWrite->value]);
    $routes = financeAbilityRoutes('finance:read');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        Auth::forgetGuards();

        $this->withToken($token)->json(financeAbilityMethod($route), financeAbilityPath($route))
            ->assertForbidden();
    }
});

it('answers 403 on every finance write route to a token that holds only finance:read', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), [ApiAbility::FinanceRead->value]);
    $routes = financeAbilityRoutes('finance:write');
    $statuses = [];

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        Auth::forgetGuards();

        $statuses[] = $this->withToken($token)->json(financeAbilityMethod($route), financeAbilityPath($route))->getStatusCode();
    }

    expect($statuses)->toBe(array_fill(0, count($routes), 403));
});

it('lets a token with the right finance ability past the ability check, so the 403 above is the ability and nothing else', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), [ApiAbility::FinanceRead->value]);

    $this->withToken($token)->getJson('/api/v1/transactions/'.Str::uuid())
        ->assertNotFound();
});
