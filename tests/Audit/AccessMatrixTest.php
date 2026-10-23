<?php

declare(strict_types=1);

use App\Domain\Identity\ApiAbility;
use App\Filament\Pages\Auth\AcceptInvitation;
use App\Filament\Pages\Dashboard;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ProjectBoard;
use App\Filament\Widgets\PartnerTasks;
use App\Livewire\Signal\SignalDay;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Livewire\Livewire;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;
use Tests\Support\CanaryRegistry;
use Tests\Support\CardPaymentFixtures;
use Tests\Support\PanelInventory;
use Tests\Support\PartnerMatrix;
use Tests\Support\RouteInventory;

/*
 * US-01, AUD-02, D-01, D-02, D-11, R-12-03-1/2/3: the permission matrix of the screens and endpoints.
 *
 * tests/Support/PartnerMatrix.php declares, as literal data, what the Admin and a Partner with a client may
 * open: every Resource, Page, Widget, cluster and relation manager of the admin panel (plus the test-only canary
 * resource while the harness is on). Completeness is checked in both directions, so a discovered class without a
 * row fails the build and so does a row without a class (R-12-03-1). For every row the observed access of the
 * Admin and of a Partner (canAccess, canView or canViewForRecord on a real canary record of client A, R-12-03-2)
 * equals the declared one, and every Resource index and panel Page answers an HTTP request 200 when declared
 * allowed and 403 when refused. The declaration is never derived from the code under test, and a guard test
 * fails if the matrix file refers to the access-rule reader, the audience enum or a policy (D-01).
 * The same is done for every /api/v1 route (its one ability, an Admin token with only that ability, a Partner token
 * with every ability), every plain Livewire component (an Admin mounts it, a Partner is refused) and the Horizon
 * dashboard (the Admin is admitted, a Partner is answered 403 on every Horizon GET route).
 * Where the code differs from the draft the row states what the code does and the difference is reported to the
 * owner for sign-off (R-12-03-3, D-02).
 */

/**
 * What differs between the discovered set and the declared set, one line per offender; empty when they match.
 * Pure over its arguments, so the self-tests can feed it planted classes and planted rows.
 *
 * @param  list<string>  $discovered
 * @param  list<string>  $declared
 * @return list<string>
 */
function auditAccessDifferences(string $kind, array $discovered, array $declared): array
{
    $differences = [];

    foreach (array_diff($discovered, $declared) as $class) {
        $differences[] = "{$kind} {$class} has no PartnerMatrix row";
    }

    foreach (array_diff($declared, $discovered) as $class) {
        $differences[] = "PartnerMatrix declares {$kind} {$class} but no such {$kind} exists";
    }

    sort($differences);

    return $differences;
}

/**
 * The declared panel rows that apply to this run: the test-only canary row only counts while the harness is on.
 *
 * @return array<class-string, array{admin: bool, partner: bool, reason: string, testOnly?: bool}>
 */
function auditAccessPanelRows(): array
{
    return array_filter(
        PartnerMatrix::panelClasses(),
        static fn (array $row): bool => ! ($row['testOnly'] ?? false) || config('kokpit.canary_harness') === true,
    );
}

/**
 * What in the matrix source breaks the rule that the declaration is literal: a reference to the access-rule
 * reader, the audience enum, a policy, a gate or an access method. Pure over the source text.
 *
 * @return list<string>
 */
function auditAccessLiteralProblems(string $source): array
{
    $problems = [];

    foreach (['AccessRule', 'Audience', 'Policy', 'Policies', 'Gate::', 'canAccess', 'canView', 'canViewForRecord'] as $needle) {
        if (str_contains($source, $needle)) {
            $problems[] = "PartnerMatrix refers to {$needle}";
        }
    }

    return $problems;
}

/**
 * The names of the /api/v1 routes of the router, sorted.
 *
 * @return list<string>
 */
function auditAccessApiRouteNames(): array
{
    $names = [];

    foreach (RouteInventory::routes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/')) {
            $names[] = RouteInventory::key($route);
        }
    }

    sort($names, SORT_STRING);

    return $names;
}

/**
 * The route of the router with that key.
 */
function auditAccessRoute(string $key): LaravelRoute
{
    foreach (RouteInventory::routes() as $route) {
        if (RouteInventory::key($route) === $key) {
            return $route;
        }
    }

    throw new LogicException("No route has the key {$key}.");
}

/**
 * The parameters a plain Livewire component is mounted with.
 *
 * @param  class-string  $class
 * @return array<string, mixed>
 */
function auditAccessLivewireParameters(string $class): array
{
    return $class === SignalDay::class ? ['forDate' => now()->toDateString()] : [];
}

it('declares every panel class and only existing ones', function (): void {
    $differences = auditAccessDifferences('panel class', PanelInventory::panelClasses(), array_keys(auditAccessPanelRows()));

    expect($differences)->toBe([], implode("\n", $differences));
});

it('does not pass vacuously: the discovery sees every kind of panel class', function (): void {
    $classes = PanelInventory::panelClasses();

    expect(count($classes))->toBeGreaterThan(60)
        ->and($classes)->toContain(Dashboard::class)
        ->and($classes)->toContain(ProjectResource::class)
        ->and($classes)->toContain(ProjectBoard::class)
        ->and($classes)->toContain(PartnerTasks::class)
        ->and($classes)->toContain(PartnerTaskResource::class)
        ->and($classes)->toContain(PartnerTaskCommentsRelationManager::class)
        ->and($classes)->toContain(AcceptInvitation::class)
        ->and(array_keys(PanelInventory::relationManagers()))->toContain(ContactsRelationManager::class);
});

it('reports an undeclared panel class and a row without a class', function (): void {
    $planted = 'App\\Filament\\Pages\\PlantedAuditPage';
    $ghost = 'App\\Filament\\Pages\\GhostAuditPage';

    $undeclared = auditAccessDifferences('panel class', [...PanelInventory::panelClasses(), $planted], array_keys(auditAccessPanelRows()));
    $stale = auditAccessDifferences('panel class', PanelInventory::panelClasses(), [...array_keys(auditAccessPanelRows()), $ghost]);

    expect($undeclared)->toBe(["panel class {$planted} has no PartnerMatrix row"])
        ->and($stale)->toBe(["PartnerMatrix declares panel class {$ghost} but no such panel class exists"])
        ->and(auditAccessDifferences('panel class', ['A', 'B'], ['A', 'B']))->toBe([]);
});

it('keeps every row reasoned', function (): void {
    expect(PartnerMatrix::problems())->toBe([]);
});

it('keeps the matrix literal', function (): void {
    $source = file_get_contents(base_path('tests/Support/PartnerMatrix.php'));

    expect(auditAccessLiteralProblems((string) $source))->toBe([]);
});

it('reports a matrix source that reads the access rules', function (): void {
    expect(auditAccessLiteralProblems('<?php return AccessRules::allows(Foo::class);'))->toBe(['PartnerMatrix refers to AccessRule'])
        ->and(auditAccessLiteralProblems('<?php return Audience::AdminOnly;'))->toBe(['PartnerMatrix refers to Audience'])
        ->and(auditAccessLiteralProblems('<?php return Foo::class => [];'))->toBe([]);
});

it('declares every /api/v1 route and only existing ones', function (): void {
    $differences = auditAccessDifferences('API route', auditAccessApiRouteNames(), array_keys(PartnerMatrix::apiEndpoints()));

    expect(auditAccessApiRouteNames())->toContain('api.v1.time-entries.index')
        ->and($differences)->toBe([], implode("\n", $differences));
});

it('reports an undeclared API route and a row without a route', function (): void {
    $names = auditAccessApiRouteNames();
    $declared = array_keys(PartnerMatrix::apiEndpoints());

    expect(auditAccessDifferences('API route', [...$names, 'api.v1.planted'], $declared))->toBe(['API route api.v1.planted has no PartnerMatrix row'])
        ->and(auditAccessDifferences('API route', $names, [...$declared, 'api.v1.ghost']))->toBe(['PartnerMatrix declares API route api.v1.ghost but no such API route exists']);
});

it('demands exactly the declared ability on {0}', function (string $name): void {
    $row = PartnerMatrix::apiEndpoints()[$name];
    $route = auditAccessRoute($name);
    $abilityMiddleware = array_values(array_filter(
        RouteInventory::middleware($route),
        static fn (string $middleware): bool => str_starts_with($middleware, CheckAbilities::class),
    ));

    expect($abilityMiddleware)->toBe([CheckAbilities::class.':'.$row['ability']]);

    $path = RouteInventory::path($route);
    $method = RouteInventory::method($route);

    // Both tokens are made first and the guards are forgotten right before each request, as in the isolation
    // suite: a guard that was resolved in between would answer the next request as the previous token's user.
    $adminToken = ApiFixtures::token(ApiFixtures::admin(), [$row['ability']]);
    $partnerToken = ApiFixtures::token(Canary::partnerFor(Canary::twoClients()[0]), ApiAbility::values());

    Auth::forgetGuards();
    $adminStatus = $this->withToken($adminToken)->json($method, $path)->getStatusCode();

    Auth::forgetGuards();
    $partnerStatus = $this->withToken($partnerToken)->json($method, $path)->getStatusCode();

    expect($adminStatus)->not->toBeIn([401, 403], "an Admin token with only {$row['ability']} on {$name}")
        ->and($partnerStatus)->toBe($row['partner'] ? $adminStatus : 401);
})->with(array_map(
    static fn (string $name): array => [$name],
    array_keys(PartnerMatrix::apiEndpoints()),
));

it('declares every Livewire component and only existing ones', function (): void {
    $differences = auditAccessDifferences('Livewire component', PanelInventory::livewireComponents(), array_keys(PartnerMatrix::livewireComponents()));

    expect(PanelInventory::livewireComponents())->toContain(SignalDay::class)
        ->and($differences)->toBe([], implode("\n", $differences));
});

it('reports an undeclared Livewire component and a row without a component', function (): void {
    $components = PanelInventory::livewireComponents();
    $declared = array_keys(PartnerMatrix::livewireComponents());

    expect(auditAccessDifferences('Livewire component', [...$components, 'App\\Livewire\\Planted'], $declared))->toBe(['Livewire component App\\Livewire\\Planted has no PartnerMatrix row'])
        ->and(auditAccessDifferences('Livewire component', $components, [...$declared, 'App\\Livewire\\Ghost']))->toBe(['PartnerMatrix declares Livewire component App\\Livewire\\Ghost but no such Livewire component exists']);
});

it('lets the Admin mount and refuses a Partner on {0}', function (string $class): void {
    $row = PartnerMatrix::livewireComponents()[$class];
    $parameters = auditAccessLivewireParameters($class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Auth::forgetGuards();
    $this->actingAs(Canary::admin());
    $admin = Livewire::test($class, $parameters);

    Auth::forgetGuards();
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));
    $partner = Livewire::test($class, $parameters);

    $row['admin'] ? $admin->assertSuccessful() : $admin->assertForbidden();
    $row['partner'] ? $partner->assertSuccessful() : $partner->assertForbidden();
})->with(array_map(
    static fn (string $class): array => [$class],
    array_keys(PartnerMatrix::livewireComponents()),
));

it('admits the Admin to the Horizon dashboard and refuses a Partner on every Horizon GET route', function (): void {
    $horizon = PartnerMatrix::horizon();
    $admin = Canary::admin();
    $partner = Canary::partnerFor(Canary::twoClients()[0]);

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBe($horizon['admin'])
        ->and(Gate::forUser($partner)->allows('viewHorizon'))->toBe($horizon['partner']);

    $routes = array_values(array_filter(
        RouteInventory::routes(),
        static fn (LaravelRoute $route): bool => str_starts_with(RouteInventory::key($route), $horizon['prefix']) && RouteInventory::method($route) === 'GET',
    ));

    expect($routes)->not->toBeEmpty()
        ->and(array_map(RouteInventory::key(...), $routes))->toContain($horizon['dashboard']);

    Auth::forgetGuards();
    $dashboard = $this->actingAs($admin)->get(RouteInventory::path(auditAccessRoute($horizon['dashboard'])));
    $adminStatus = $dashboard->getStatusCode();

    $refused = [];

    foreach ($routes as $route) {
        Auth::forgetGuards();
        $status = $this->actingAs($partner)->get(RouteInventory::path($route))->getStatusCode();

        if ($status !== ($horizon['partner'] ? 200 : 403)) {
            $refused[] = RouteInventory::key($route).": got {$status}";
        }
    }

    expect($adminStatus)->toBe($horizon['admin'] ? 200 : 403)
        ->and($refused)->toBe([], implode("\n", $refused));
});

describe('with canaries of two clients seeded', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CanaryRegistry::prepare();

        [$this->clientA, $this->clientB] = Canary::twoClients();
        $this->canaryA = Canary::canary('client_a');
        $this->canaryB = Canary::canary('client_b');

        CanaryRegistry::seedAll($this->clientA, $this->canaryA);
        CanaryRegistry::seedAll($this->clientB, $this->canaryB);

        // The unresolved-payments widget shows itself only while one card payment is unresolved.
        CardPaymentFixtures::unmatched();
    });

    afterEach(function (): void {
        CanaryRegistry::cleanup();
    });

    it('behaves for the Admin and for a Partner as {0} declares', function (string $class): void {
        $row = auditAccessPanelRows()[$class] ?? null;

        if ($row === null) {
            $this->markTestSkipped("{$class} is a test-only row and the canary harness is off.");
        }

        $owner = array_key_exists($class, PanelInventory::relationManagers())
            ? PanelInventory::canaryRecord(PanelInventory::ownerModel($class), $this->canaryA)
            : null;

        $observed = [];

        foreach (['admin' => static fn () => Canary::admin(), 'partner' => fn () => Canary::partnerFor($this->clientA)] as $role => $make) {
            Auth::forgetGuards();
            $this->actingAs($make());
            $observed[$role] = PanelInventory::observedAccess($class, $owner);
        }

        expect($observed)->toBe(['admin' => $row['admin'], 'partner' => $row['partner']], $class);
    })->with(array_map(
        static fn (string $class): array => [$class],
        array_keys(PartnerMatrix::panelClasses()),
    ));

    it('answers 200 or 403 on every Resource index and panel Page as declared', function (): void {
        $offenders = [];
        $requested = 0;

        foreach (auditAccessPanelRows() as $class => $row) {
            $url = PanelInventory::requestUrl($class, $this->canaryA);

            if ($url === null) {
                continue;
            }

            foreach (['admin' => static fn () => Canary::admin(), 'partner' => fn () => Canary::partnerFor($this->clientA)] as $role => $make) {
                Auth::forgetGuards();
                $expected = $row[$role] ? 200 : 403;
                $status = $this->actingAs($make())->get($url)->getStatusCode();
                $requested++;

                if ($status !== $expected) {
                    $offenders[] = "{$class} as {$role}: expected {$expected}, got {$status}";
                }
            }
        }

        expect($requested)->toBeGreaterThan(40)
            ->and($offenders)->toBe([], implode("\n", $offenders));
    });
});
