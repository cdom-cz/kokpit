<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\ApiAbility;
use App\Domain\Shared\Auth\PartnerContext;
use Filament\Facades\Filament;
use Illuminate\Pagination\Cursor;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;
use Tests\Support\CanaryRegistry;

/*
 * D-01, D-16, T-07-56: a route walk over every /api/v1 route. Everything is derived from the
 * route collection, so a route added by a later plan is walked without editing this file: it
 * needs a token (auth:sanctum), exactly one ability and the rate limit of that ability, and it
 * answers 401 to a guest and to a Partner token and 403 to an Admin token without its ability.
 * A forged cursor on a list route is answered by the same 401 and 403, never by the cursor validator (T-07-69).
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
    $limits = [
        ApiAbility::TimeRead->value => 'throttle:api-read',
        ApiAbility::TimeWrite->value => 'throttle:api-write',
        ApiAbility::FinanceRead->value => 'throttle:api-read',
        ApiAbility::FinanceWrite->value => 'throttle:api-write',
    ];
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

/**
 * The GET routes of the walk that have no route parameter: the list routes, which take a cursor.
 *
 * @return list<LaravelRoute>
 */
function apiWalkListRoutes(): array
{
    return array_values(array_filter(
        apiWalkRoutes(),
        static fn (LaravelRoute $route): bool => apiWalkMethod($route) === 'GET' && ! str_contains($route->uri(), '{'),
    ));
}

/**
 * A cursor no list issued: it holds no sort column and no id, so the cursor rule would refuse it with 422.
 */
function apiWalkForgedCursor(): string
{
    return (new Cursor([], true))->encode();
}

it('finds the list routes of the walk, so the forged cursor tests are not vacuous', function (): void {
    $paths = array_map(static fn (LaravelRoute $route): string => $route->uri(), apiWalkListRoutes());

    expect($paths)->toContain('api/v1/time-entries')
        ->and($paths)->toContain('api/v1/clients');
});

it('answers 401 to a guest with a forged cursor on every list route, never 422 or 500', function (): void {
    foreach (apiWalkListRoutes() as $route) {
        $this->json('GET', apiWalkPath($route), ['cursor' => apiWalkForgedCursor()])
            ->assertUnauthorized();
    }
});

it('answers 403 to an Admin token without the ability with a forged cursor on every list route, never 422 or 500', function (): void {
    $admin = ApiFixtures::admin();

    foreach (apiWalkListRoutes() as $route) {
        $token = ApiFixtures::token($admin, array_values(array_diff(ApiAbility::values(), [apiWalkAbility($route)])));
        Auth::forgetGuards();

        $this->withToken($token)->json('GET', apiWalkPath($route), ['cursor' => apiWalkForgedCursor()])
            ->assertForbidden();
    }
});

/*
 * The API line of the canary harness (T-07-54): rows of two clients carry canary strings in every model, and a guest
 * and a Partner token of client A, who is allowed nothing, request every api/v1 route. Each answer is 401 and no
 * body holds a canary of either client. The control proves the canaries would show up if authentication let a
 * request through: an Admin token reads them from the time entry list.
 */
describe('with canaries of two clients seeded', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CanaryRegistry::prepare();

        [$this->clientA, $this->clientB] = Canary::twoClients();
        $this->canaryA = Canary::canary('client_a');
        $this->canaryB = Canary::canary('client_b');

        CanaryRegistry::seedAll($this->clientA, $this->canaryA);
        CanaryRegistry::seedAll($this->clientB, $this->canaryB);
    });

    afterEach(function (): void {
        CanaryRegistry::cleanup();
    });

    it('lets an Admin token read the seeded canaries, so the proof below is not vacuous', function (): void {
        $token = ApiFixtures::token(Canary::admin(), ApiAbility::values());
        $body = $this->withToken($token)->getJson('/api/v1/time-entries?per_page=200')->assertOk()->getContent();

        expect($body)->toContain($this->canaryA)
            ->and($body)->toContain($this->canaryB);
    });

    it('lets a finance:read token read the transaction canary of a client by UUID', function (): void {
        $id = app(PartnerContext::class)->runAsSystem(
            fn (): string => Transaction::query()->where('counterparty', $this->canaryA)->sole()->id,
        );

        $response = $this->withToken(ApiFixtures::token(Canary::admin(), [ApiAbility::FinanceRead->value]))
            ->getJson('/api/v1/transactions/'.$id)
            ->assertOk();

        expect(array_keys($response->json('data')))->toBe([
            'id', 'type', 'transaction_date', 'category_id', 'amount_minor', 'currency', 'czk_minor', 'czk_source',
            'exchange_rate', 'exchange_unit_amount', 'exchange_rate_date', 'description', 'counterparty',
            'document_number', 'client_id', 'project_id', 'created_at', 'updated_at',
        ])
            ->and($response->json('data.id'))->toBe($id)
            ->and($response->getContent())->toContain($this->canaryA)
            ->and($response->getContent())->not->toContain($this->canaryB);
    });

    it('answers a guest 401 on every api/v1 route and leaks no canary', function (): void {
        foreach (apiWalkRoutes() as $route) {
            $response = $this->json(apiWalkMethod($route), apiWalkPath($route))->assertUnauthorized();

            expect($response->getContent())->not->toContain($this->canaryA)
                ->and($response->getContent())->not->toContain($this->canaryB);
        }
    });

    it('answers the token of a Partner of client A 401 on every api/v1 route and leaks no canary', function (): void {
        $token = ApiFixtures::token(Canary::partnerFor($this->clientA), ApiAbility::values());

        foreach (apiWalkRoutes() as $route) {
            Auth::forgetGuards();

            $response = $this->withToken($token)->json(apiWalkMethod($route), apiWalkPath($route))->assertUnauthorized();

            expect($response->getContent())->not->toContain($this->canaryA)
                ->and($response->getContent())->not->toContain($this->canaryB);
        }
    });
});
