<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
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

    $this->resources = walkedResourceMap($this->recordA->id, $this->recordB->id, $this->canaryA, $this->canaryB);
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
 * The URL of a walked route. A route with a {record} parameter gets the given record id; the file route
 * (a {document} parameter) gets the visible canary document of the requested side ('a' or 'b'), signed for
 * the signed-in user through DocumentUrl so that the walk reaches the controller instead of stopping at the
 * signature check. Any other parameter fails the walk so that a new kind of route is looked at on purpose.
 */
function walkedUrl(LaravelRoute $route, string $recordId, string $side = 'a'): string
{
    $parameters = [];

    foreach ($route->parameterNames() as $parameter) {
        if ($parameter === 'document' && $route->getName() === DocumentUrl::ROUTE) {
            $user = auth()->user();

            if (! $user instanceof User) {
                throw new LogicException('The route walk signs the file route for the signed-in user; sign in before walking.');
            }

            return DocumentUrl::download(walkedDocument($side), $user);
        }

        if ($parameter !== 'record') {
            throw new LogicException("The route walk does not know the parameter [{$parameter}] of route [{$route->getName()}]; teach it how to fill it.");
        }

        $parameters['record'] = $recordId;
    }

    return route((string) $route->getName(), $parameters);
}

/**
 * The visible canary document of a side: the file of the canary project that the Document fixture attaches
 * under the canary name (the four hidden twins carry a suffix).
 */
function walkedDocument(string $side): Document
{
    $canary = $side === 'a' ? test()->canaryA : test()->canaryB;

    return app(PartnerContext::class)->runAsSystem(
        static fn (): Document => Document::query()->where('name', $canary.'.txt')->firstOrFail(),
    );
}

/**
 * Every resource that has record routes: who may open its record routes and the
 * record id to request for each side (client A is the signed-in Partner, client B
 * the other client). The walk fails on a resource that is not listed, so a new
 * resource is looked at on purpose. Admin-only resources (`projects`, `clients`, `tasks` and `time-entries`) are listed with `partner => false`: a Partner
 * must get 403 on all their routes, whatever record is requested.
 *
 * @return array<string, array{partner: bool, a: string, b: string}>
 */
function walkedResourceMap(string $canaryRecordA, string $canaryRecordB, string $canaryA, string $canaryB): array
{
    $projectId = static fn (string $canary): string => app(PartnerContext::class)->runAsSystem(
        static fn (): string => Project::query()->where('name', $canary)->firstOrFail()->id,
    );

    $clientId = static fn (string $canary): string => app(PartnerContext::class)->runAsSystem(
        static fn (): string => Project::query()->where('name', $canary)->firstOrFail()->client_id,
    );

    // The task canary is the title; a task is addressed by its reference (KEY-N).
    $taskReference = static fn (string $canary): string => app(PartnerContext::class)->runAsSystem(
        static fn (): string => Task::query()->where('title', $canary)->firstOrFail()->reference,
    );

    // The time entry canary is the description.
    $entryId = static fn (string $canary): string => app(PartnerContext::class)->runAsSystem(
        static fn (): string => TimeEntry::query()->where('description', $canary)->firstOrFail()->id,
    );

    // The transaction canary is the counterparty.
    $transactionId = static fn (string $canary): string => app(PartnerContext::class)->runAsSystem(
        static fn (): string => Transaction::query()->where('counterparty', $canary)->firstOrFail()->id,
    );

    return [
        'canary-records' => ['partner' => true, 'a' => $canaryRecordA, 'b' => $canaryRecordB],
        'my-projects' => ['partner' => true, 'a' => $projectId($canaryA), 'b' => $projectId($canaryB)],
        'projects' => ['partner' => false, 'a' => $projectId($canaryA), 'b' => $projectId($canaryB)],
        'clients' => ['partner' => false, 'a' => $clientId($canaryA), 'b' => $clientId($canaryB)],
        'tasks' => ['partner' => false, 'a' => $taskReference($canaryA), 'b' => $taskReference($canaryB)],
        'time-entries' => ['partner' => false, 'a' => $entryId($canaryA), 'b' => $entryId($canaryB)],
        'transactions' => ['partner' => false, 'a' => $transactionId($canaryA), 'b' => $transactionId($canaryB)],
        'my-tasks' => ['partner' => true, 'a' => $taskReference($canaryA), 'b' => $taskReference($canaryB)],
    ];
}

/**
 * The resource slug of a route name such as filament.admin.resources.my-projects.view,
 * or null for a route that does not belong to a resource.
 */
function walkedResourceSlug(LaravelRoute $route): ?string
{
    $name = (string) $route->getName();
    $prefix = 'filament.admin.resources.';

    if (! str_starts_with($name, $prefix)) {
        return null;
    }

    $rest = substr($name, strlen($prefix));

    return substr($rest, 0, (int) strrpos($rest, '.'));
}

/**
 * The record id to request on a record route for the given side ('a' or 'b').
 * An unknown resource throws, like an unknown route parameter does.
 *
 * @param  array<string, array{partner: bool, a: string, b: string}>  $resources
 */
function walkedRecordId(LaravelRoute $route, array $resources, string $side): string
{
    $slug = walkedResourceSlug($route);

    if ($slug === null || ! isset($resources[$slug])) {
        throw new LogicException("The route walk does not know the resource of route [{$route->getName()}]; teach walkedResourceMap() who may open it and which record ids to request.");
    }

    return $resources[$slug][$side];
}

/**
 * Whether a Partner may open the record routes of the resource of this route.
 *
 * @param  array<string, array{partner: bool, a: string, b: string}>  $resources
 */
function walkedPartnerMayOpen(LaravelRoute $route, array $resources): bool
{
    walkedRecordId($route, $resources, 'a');

    return $resources[(string) walkedResourceSlug($route)]['partner'];
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
        ->and($names)->toContain('filament.admin.resources.canary-records.view')
        ->and($names)->toContain('filament.admin.resources.my-projects.index')
        ->and($names)->toContain('filament.admin.resources.my-projects.view')
        ->and($names)->toContain('filament.admin.files.document');
});

it('refuses a record route of a resource it does not know, so a new resource is looked at on purpose', function (): void {
    $route = Route::getRoutes()->getByName('filament.admin.resources.my-projects.view');

    expect(static fn () => walkedRecordId($route, ['canary-records' => ['partner' => true, 'a' => 'x', 'b' => 'y']], 'a'))
        ->toThrow(LogicException::class, 'does not know the resource');
});

it('shows Partner A nothing of client B on any panel route', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    foreach (walkedRoutes() as $route) {
        $url = walkedUrl($route, walkedHasRecord($route) ? walkedRecordId($route, $this->resources, 'b') : $this->recordB->id, 'b');
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

it('serves Partner A the record routes of Partner-allowed resources for a client A record and refuses the Admin-only ones', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $served = 0;

    foreach (walkedRoutes() as $route) {
        if (! walkedHasRecord($route)) {
            continue;
        }

        $url = walkedUrl($route, walkedRecordId($route, $this->resources, 'a'), 'a');
        $response = $this->get($url);

        if (! walkedPartnerMayOpen($route, $this->resources)) {
            $response->assertForbidden();

            continue;
        }

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
        $url = walkedUrl($route, walkedHasRecord($route) ? walkedRecordId($route, $this->resources, 'a') : $this->recordA->id, 'a');
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

it('refuses the file route for a document of client B to Partner A with a 404 and no trace of client B', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $url = walkedUrl(Route::getRoutes()->getByName(DocumentUrl::ROUTE), $this->recordB->id, 'b');
    $response = $this->get($url)->assertNotFound();
    $body = (string) $response->getContent();

    expect(str_contains($body, $this->canaryB))->toBeFalse()
        ->and(str_contains($body, $this->clientB))->toBeFalse();
});

it('serves the Admin the file route for the documents of both clients, so the refusal of Partner A comes from the scope', function (): void {
    $this->actingAs(Canary::admin());

    foreach (['a', 'b'] as $side) {
        $url = walkedUrl(Route::getRoutes()->getByName(DocumentUrl::ROUTE), $this->recordA->id, $side);

        $this->get($url)->assertOk();
    }
});

it('answers a Partner without a client 403 or 404 on the file route and shows nothing of either client', function (): void {
    $this->actingAs(Canary::partnerFor(null));

    foreach (['a', 'b'] as $side) {
        $url = walkedUrl(Route::getRoutes()->getByName(DocumentUrl::ROUTE), $this->recordA->id, $side);
        $response = $this->get($url);
        $body = (string) $response->getContent();

        expect($response->getStatusCode())->toBeIn([403, 404])
            ->and(str_contains($body, $this->canaryA))->toBeFalse()
            ->and(str_contains($body, $this->canaryB))->toBeFalse();
    }
});

it('still refuses a parameter it does not know, so a new kind of route is looked at on purpose', function (): void {
    expect(static fn () => walkedUrl(new LaravelRoute('GET', '/x/{unknown}', []), 'x'))
        ->toThrow(LogicException::class, 'does not know the parameter');
});
