<?php

declare(strict_types=1);

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerProjectResource\Pages\ListPartnerProjects;
use Filament\Facades\Filament;
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
