<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CanaryRecord;
use Tests\Support\CanaryRegistry;
use Tests\Support\Filament\CanaryRecordResource;
use Tests\Support\Filament\CanaryRecordResource\Pages\ListCanaryRecords;

/*
 * D-04 route walk: Partner A requests every GET route of the panel and no response body,
 * Livewire snapshots included, may contain anything of client B. Record routes are
 * requested with a client B id (expect 403 or 404) and with a client A id (expect 200).
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CanaryRegistry::prepare();

    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->canaryA = Canary::canary('client_a');
    $this->canaryB = Canary::canary('client_b');

    CanaryRegistry::seedAll($this->clientA, $this->canaryA);
    CanaryRegistry::seedAll($this->clientB, $this->canaryB);

    $this->recordA = CanaryRecord::query()->where('secret', $this->canaryA)->withoutGlobalScopes()->firstOrFail();
    $this->recordB = CanaryRecord::query()->where('secret', $this->canaryB)->withoutGlobalScopes()->firstOrFail();
});

afterEach(function (): void {
    CanaryRegistry::cleanup();
});

/**
 * Every GET route of the admin panel except the guest-only authentication routes
 * (login, which redirects a signed-in user, and the logout POST).
 *
 * @return list<LaravelRoute>
 */
function walkedRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName() ?? '';

        if (! str_starts_with($name, 'filament.admin.') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (in_array($name, ['filament.admin.auth.login', 'filament.admin.auth.logout'], true)) {
            continue;
        }

        $routes[] = $route;
    }

    usort($routes, static fn (LaravelRoute $a, LaravelRoute $b): int => strcmp((string) $a->getName(), (string) $b->getName()));

    return $routes;
}

/**
 * The URL of a walked route. A route with a {record} parameter gets the given
 * record id; any other parameter fails the walk so that a new kind of route is
 * looked at on purpose.
 */
function walkedUrl(LaravelRoute $route, string $recordId): string
{
    $parameters = [];

    foreach ($route->parameterNames() as $parameter) {
        if ($parameter !== 'record') {
            throw new LogicException("The route walk does not know the parameter [{$parameter}] of route [{$route->getName()}]; teach it how to fill it.");
        }

        $parameters['record'] = $recordId;
    }

    return route((string) $route->getName(), $parameters);
}

function walkedHasRecord(LaravelRoute $route): bool
{
    return in_array('record', $route->parameterNames(), true);
}

it('walks at least the dashboard, the canary list and the canary record route', function (): void {
    $names = array_map(static fn (LaravelRoute $route): ?string => $route->getName(), walkedRoutes());

    expect(count($names))->toBeGreaterThanOrEqual(3)
        ->and($names)->toContain('filament.admin.pages.dashboard')
        ->and($names)->toContain('filament.admin.resources.canary-records.index')
        ->and($names)->toContain('filament.admin.resources.canary-records.view');
});

it('shows Partner A nothing of client B on any panel route', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    foreach (walkedRoutes() as $route) {
        $url = walkedUrl($route, $this->recordB->id);
        $response = $this->get($url);
        $body = $response->getContent();

        expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], "{$url} answered {$response->getStatusCode()}")
            ->and(str_contains((string) $body, $this->canaryB))->toBeFalse("{$url} leaked the client B canary")
            ->and(str_contains((string) $body, $this->clientB))->toBeFalse("{$url} leaked the client B id");

        if (walkedHasRecord($route)) {
            expect($response->getStatusCode())->toBeIn([403, 404], "{$url} must refuse a client B record");
        }
    }
});

it('serves Partner A the record routes for a client A record', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $served = 0;

    foreach (walkedRoutes() as $route) {
        if (! walkedHasRecord($route)) {
            continue;
        }

        $url = walkedUrl($route, $this->recordA->id);
        $response = $this->get($url);

        $response->assertOk();
        expect(str_contains((string) $response->getContent(), $this->canaryA))->toBeTrue("{$url} must show the own canary");
        $served++;
    }

    expect($served)->toBeGreaterThanOrEqual(1);
});

it('shows the own canary on the list page, so the walk sees data', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $body = (string) $this->get(route('filament.admin.resources.canary-records.index'))->assertOk()->getContent();

    expect(str_contains($body, $this->canaryA))->toBeTrue()
        ->and(str_contains($body, $this->canaryB))->toBeFalse();
});

it('serves an Admin the client B record that Partner A is refused, so the refusal comes from the scope', function (): void {
    $this->actingAs(Canary::admin());

    $url = route('filament.admin.resources.canary-records.view', ['record' => $this->recordB->id]);
    $body = (string) $this->get($url)->assertOk()->getContent();

    expect(str_contains($body, $this->canaryB))->toBeTrue()
        ->and(str_contains((string) $this->get(route('filament.admin.resources.canary-records.index'))->getContent(), $this->canaryA))->toBeTrue();
});

it('shows a Partner without a client nothing of either client on any panel route', function (): void {
    $this->actingAs(Canary::partnerFor(null));

    foreach (walkedRoutes() as $route) {
        $url = walkedUrl($route, $this->recordA->id);
        $response = $this->get($url);
        $body = (string) $response->getContent();

        expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], $url)
            ->and(str_contains($body, $this->canaryA))->toBeFalse("{$url} leaked the client A canary")
            ->and(str_contains($body, $this->canaryB))->toBeFalse("{$url} leaked the client B canary");
    }
});

it('keeps client B records out of the Livewire table of Partner A, searched or not', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    Livewire::test(ListCanaryRecords::class)
        ->assertCanSeeTableRecords([$this->recordA])
        ->assertCanNotSeeTableRecords([$this->recordB])
        ->searchTable($this->canaryA)
        ->assertCanSeeTableRecords([$this->recordA])
        ->searchTable($this->canaryB)
        ->assertCanNotSeeTableRecords([$this->recordA, $this->recordB]);
});

it('keeps client B records out of global search for Partner A', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    // Both canaries start with the same prefix, so an unscoped search would return both.
    $titles = CanaryRecordResource::getGlobalSearchResults('CANARY')
        ->map(static fn ($result): string => (string) $result->title)
        ->all();

    // The policy filters the result URL as well; the query itself must already be scoped.
    expect($titles)->toBe([$this->canaryA])
        ->and(CanaryRecordResource::getGlobalSearchEloquentQuery()->count())->toBe(1);

    $this->actingAs(Canary::admin());

    $adminTitles = CanaryRecordResource::getGlobalSearchResults('CANARY')
        ->map(static fn ($result): string => (string) $result->title)
        ->sort()
        ->values()
        ->all();

    expect($adminTitles)->toEqualCanonicalizing([$this->canaryA, $this->canaryB]);
});

it('returns no global search result to a Partner without a client', function (): void {
    $this->actingAs(Canary::partnerFor(null));

    expect(CanaryRecordResource::getGlobalSearchResults('CANARY')->all())->toBe([]);
});
