<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerProjectResource;
use App\Filament\Partner\Resources\PartnerProjectResource\Pages\ListPartnerProjects;
use App\Filament\Support\ProjectColumns;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The read-only "Moje projekty" resource of a Partner (PR-04, D-07). Every
 * name, key and canary is fictional and assembled at runtime; rows are written
 * in system runs.
 */

/**
 * Creates a project of the given client in a system run.
 *
 * @param  array<string, mixed>  $attributes
 */
function partnerProjectCreate(string $clientId, array $attributes = []): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => Project::factory()->create([
        'client_id' => $clientId,
        'key' => Canary::projectKey(),
        'client_visible' => true,
        ...$attributes,
    ]));
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->canaryA = Canary::canary('client_a');
    $this->canaryB = Canary::canary('client_b');

    $this->projectA = partnerProjectCreate($this->clientA, ['name' => $this->canaryA]);
    $this->projectB = partnerProjectCreate($this->clientB, ['name' => $this->canaryB]);
});

it('lists the own visible project to a Partner and nothing of client B', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $body = (string) $this->get(route('filament.admin.resources.my-projects.index'))->assertOk()->getContent();

    expect(str_contains($body, $this->canaryA))->toBeTrue()
        ->and(str_contains($body, $this->canaryB))->toBeFalse()
        ->and(str_contains($body, $this->clientB))->toBeFalse()
        ->and(str_contains($body, $this->projectB->id))->toBeFalse();
});

it('shows the own visible project in the Livewire table and hides client B\'s', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    Livewire::test(ListPartnerProjects::class)
        ->assertCanSeeTableRecords([$this->projectA])
        ->assertCanNotSeeTableRecords([$this->projectB]);
});

it('opens the read-only detail of the own visible project with its name and key', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $this->get(route('filament.admin.resources.my-projects.view', ['record' => $this->projectA->id]))
        ->assertOk()
        ->assertSee($this->canaryA)
        ->assertSee($this->projectA->key);
});

it('renders the status and priority labels from the Czech enum translations', function (): void {
    $project = partnerProjectCreate($this->clientA, ['status' => 'ready_to_release', 'priority' => 'urgent']);
    $this->actingAs(Canary::partnerFor($this->clientA));

    $this->get(route('filament.admin.resources.my-projects.view', ['record' => $project->id]))
        ->assertOk()
        ->assertSee('K vypuštění')
        ->assertSee('Naléhavá');
});

it('hides the own hidden project and client B\'s visible project in the Livewire table', function (): void {
    $hidden = partnerProjectCreate($this->clientA, ['name' => Canary::canary('hidden_a'), 'client_visible' => false]);
    $visibleB = partnerProjectCreate($this->clientB, ['name' => Canary::canary('visible_b')]);
    $this->actingAs(Canary::partnerFor($this->clientA));

    Livewire::test(ListPartnerProjects::class)
        ->assertCanSeeTableRecords([$this->projectA])
        ->assertCanNotSeeTableRecords([$hidden, $visibleB, $this->projectB]);
});

it('searches only the own visible projects by name and key', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    Livewire::test(ListPartnerProjects::class)
        ->searchTable($this->canaryB)
        ->assertCanNotSeeTableRecords([$this->projectA, $this->projectB])
        ->searchTable($this->projectB->key)
        ->assertCanNotSeeTableRecords([$this->projectA, $this->projectB])
        ->searchTable($this->projectA->key)
        ->assertCanSeeTableRecords([$this->projectA])
        ->assertCanNotSeeTableRecords([$this->projectB])
        ->searchTable($this->canaryA)
        ->assertCanSeeTableRecords([$this->projectA]);
});

it('makes only name and key searchable', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $columns = Livewire::test(ListPartnerProjects::class)->instance()->getTable()->getColumns();
    $searchable = array_keys(array_filter($columns, static fn ($column): bool => $column->isSearchable()));

    expect($searchable)->toBe(['name', 'key']);
});

it('refuses a Partner the view page of a hidden, an archived and another client\'s project', function (): void {
    $hidden = partnerProjectCreate($this->clientA, ['name' => Canary::canary('hidden_a'), 'client_visible' => false]);
    $archived = partnerProjectCreate($this->clientA, ['name' => Canary::canary('archived_a')]);
    app(PartnerContext::class)->runAsSystem(static fn () => $archived->delete());
    $this->actingAs(Canary::partnerFor($this->clientA));

    foreach ([$hidden, $archived, $this->projectB] as $project) {
        $response = $this->get(route('filament.admin.resources.my-projects.view', ['record' => $project->id]));
        $body = (string) $response->getContent();

        expect($response->getStatusCode())->toBeIn([403, 404])
            ->and(str_contains($body, $this->canaryB))->toBeFalse()
            ->and(str_contains($body, $this->clientB))->toBeFalse()
            ->and(str_contains($body, $this->projectB->name))->toBeFalse();
    }
});

it('refuses the view page of an archived client\'s project once the client is archived', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $this->get(route('filament.admin.resources.my-projects.view', ['record' => $this->projectA->id]))->assertOk();

    app(PartnerContext::class)->runAsSystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());

    expect($this->get(route('filament.admin.resources.my-projects.view', ['record' => $this->projectA->id]))->getStatusCode())->toBeIn([302, 403, 404]);
});

it('gives the Admin and a Partner without a client 403 on the Partner project resource', function (): void {
    $this->actingAs(Canary::admin());
    $this->get(route('filament.admin.resources.my-projects.index'))->assertForbidden();

    $this->actingAs(Canary::partnerFor(null));
    $this->get(route('filament.admin.resources.my-projects.index'))->assertForbidden();
});

it('builds the table and the detail only from the pinned Partner-safe names', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $page = Livewire::test(ListPartnerProjects::class)->instance();
    $table = $page->getTable();
    $entries = array_values(array_map(
        static fn ($entry): string => $entry->getName(),
        PartnerProjectResource::infolist(Schema::make($page))->getFlatComponents(),
    ));

    expect(array_keys($table->getColumns()))->toBe(ProjectColumns::PARTNER_COLUMN_NAMES)
        ->and($entries)->toBe(ProjectColumns::PARTNER_ENTRY_NAMES);
});

it('keeps client, rate, price, estimate, billing type and note out of the pinned names', function (): void {
    $names = [...ProjectColumns::PARTNER_COLUMN_NAMES, ...ProjectColumns::PARTNER_ENTRY_NAMES];

    foreach (['client', 'rate', 'price', 'estimate', 'billing', 'note', 'amount', 'currency'] as $forbidden) {
        foreach ($names as $name) {
            expect(str_contains($name, $forbidden))->toBeFalse("{$name} looks like a {$forbidden} field");
        }
    }
});

it('offers no header, record or bulk action and no global search', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $table = Livewire::test(ListPartnerProjects::class)->instance()->getTable();

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getFlatActions())->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(PartnerProjectResource::canGloballySearch())->toBeFalse()
        ->and(PartnerProjectResource::getRelations())->toBe([])
        ->and(array_keys(PartnerProjectResource::getPages()))->toBe(['index', 'view']);
});

it('does not render a rate, price or client field on the list or the detail', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    foreach ([
        route('filament.admin.resources.my-projects.index'),
        route('filament.admin.resources.my-projects.view', ['record' => $this->projectA->id]),
    ] as $url) {
        $body = (string) $this->get($url)->assertOk()->getContent();

        foreach (['hourly_rate', 'fixed_price', 'billing_type', 'internal_note', 'client_id', 'client_visible'] as $forbidden) {
            expect(str_contains($body, $forbidden))->toBeFalse("{$url} rendered {$forbidden}");
        }
    }
});
