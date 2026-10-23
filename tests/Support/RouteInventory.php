<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Discovers every route the application registers, vendor and framework routes included, and checks
 * the inventory against the declaration in RouteRules (AUD-02; D-04, D-06, D-12).
 *
 * A route is keyed by its name when it has one; otherwise by "METHOD /uri", where METHOD is the first
 * method other than HEAD and the Livewire prefix, which is derived from APP_KEY and so differs per
 * machine, is replaced by "livewire-{hash}" (R-12-01-1). A route is authenticated when one of its
 * resolved middleware strings is a recognised authentication middleware class, bare or with a parameter
 * after a colon (R-12-01-2; an exact match, not a prefix, so AuthenticateSession does not count).
 * The middleware are resolved through the router, never through the route alone, because the route
 * leaves aliases such as "auth:sanctum" unresolved.
 */
final class RouteInventory
{
    /**
     * Class names of the authentication middleware recognised as "this route needs a login": the Filament
     * panel guard, the framework guard (also in its "auth:sanctum" alias form) and the Horizon guard.
     *
     * @var list<string>
     */
    public const array AUTH_MIDDLEWARE = [
        'Filament\Http\Middleware\Authenticate',
        'Illuminate\Auth\Middleware\Authenticate',
        'Laravel\Horizon\Http\Middleware\Authenticate',
    ];

    /**
     * Every route of the application, sorted by key, with no sampling (D-12).
     *
     * @return list<LaravelRoute>
     */
    public static function routes(): array
    {
        $routes = array_values(Route::getRoutes()->getRoutes());

        usort($routes, static fn (LaravelRoute $a, LaravelRoute $b): int => strcmp(self::key($a), self::key($b)));

        return $routes;
    }

    /**
     * The key of a route (R-12-01-1): its name, or "METHOD /uri" with the Livewire prefix normalised.
     */
    public static function key(LaravelRoute $route): string
    {
        $name = $route->getName();

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $uri = (string) preg_replace('#^livewire-[0-9a-f]{8}#', 'livewire-{hash}', $route->uri());

        return self::method($route).' /'.ltrim($uri, '/');
    }

    /**
     * The first method of the route other than HEAD.
     */
    public static function method(LaravelRoute $route): string
    {
        return array_values(array_diff($route->methods(), ['HEAD']))[0];
    }

    /**
     * The request path of a route, with every path parameter replaced by a fresh uuid.
     */
    public static function path(LaravelRoute $route): string
    {
        return '/'.preg_replace_callback('/\{[^}]+\}/', static fn (): string => (string) Str::uuid(), $route->uri());
    }

    /**
     * The middleware strings of a route, aliases resolved and groups expanded.
     *
     * @return list<string>
     */
    public static function middleware(LaravelRoute $route): array
    {
        return array_values(array_map('strval', app('router')->gatherRouteMiddleware($route)));
    }

    /**
     * Whether a resolved middleware string of the route is a recognised authentication middleware (R-12-01-2).
     */
    public static function isAuthenticated(LaravelRoute $route): bool
    {
        foreach (self::middleware($route) as $middleware) {
            foreach (self::AUTH_MIDDLEWARE as $class) {
                // The class itself or its parameter form ("auth:sanctum" resolves to "...Authenticate:sanctum").
                // A bare prefix would also match Filament's AuthenticateSession, which every panel route has,
                // login page included, and which authenticates nothing.
                if ($middleware === $class || str_starts_with($middleware, $class.':')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What is wrong with the inventory against the declaration; empty when it is fine. Pure over its
     * arguments, so the self-tests can feed it planted routes and planted declarations.
     *
     * @param  list<LaravelRoute>  $routes
     * @param  array<string, array{means: string, reason: string}>  $public
     * @param  array<string, array{status: int, reason: string}>  $closed
     * @return list<string>
     */
    public static function problems(array $routes, array $public, array $closed): array
    {
        $problems = [];
        $seen = [];

        foreach (array_intersect_key($public, $closed) as $key => $unused) {
            $problems[] = "{$key} is declared both public and closed in RouteRules";
        }

        foreach ($routes as $route) {
            $key = self::key($route);
            $seen[$key] = true;
            $declared = array_key_exists($key, $public) || array_key_exists($key, $closed);

            if (self::isAuthenticated($route)) {
                if ($declared) {
                    $problems[] = "{$key} is authenticated; remove the stale RouteRules entry";
                }

                continue;
            }

            if (! $declared) {
                $problems[] = "{$key} has no auth middleware and no RouteRules entry";
            }
        }

        foreach ([...array_keys($public), ...array_keys($closed)] as $key) {
            if (! isset($seen[(string) $key])) {
                $problems[] = "{$key} is declared in RouteRules but no such route exists";
            }
        }

        $problems = array_values(array_unique($problems));
        sort($problems);

        return $problems;
    }
}
