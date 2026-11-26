<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ClientKnowledgeRelationManager;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectKnowledgeRelationManager;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The Znalosti tabs of the client and of the project page are closed to a Partner, even for an article of the own
 * client (CLAUDE.md security constraint, D-07). Helpers carry the prefix `knowledgeTabsIsolation`, because Pest loads
 * every test file into one namespace. Every title is fictional or a runtime canary.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * A client and one of its projects, as the system creates them.
 *
 * @return array{0: Client, 1: Project}
 */
function knowledgeTabsIsolationOwners(): array
{
    return app(PartnerContext::class)->runAsSystem(static function (): array {
        $client = Client::factory()->create();

        return [$client, Project::factory()->create(['client_id' => $client->id])];
    });
}

it('does not admit a Partner of the client, or a Partner without a client, to either tab and admits the Admin', function (): void {
    [$client, $project] = knowledgeTabsIsolationOwners();

    foreach ([Canary::partnerFor($client->id), Canary::partnerFor(null)] as $partner) {
        $this->actingAs($partner);

        expect(ClientKnowledgeRelationManager::canViewForRecord($client, ViewClient::class))->toBeFalse()
            ->and(ProjectKnowledgeRelationManager::canViewForRecord($project, ViewProject::class))->toBeFalse();
    }

    $this->actingAs(Canary::admin());

    expect(ClientKnowledgeRelationManager::canViewForRecord($client, ViewClient::class))->toBeTrue()
        ->and(ProjectKnowledgeRelationManager::canViewForRecord($project, ViewProject::class))->toBeTrue();
});

it('refuses a Partner who mounts either tab directly', function (): void {
    [$client, $project] = knowledgeTabsIsolationOwners();

    $this->actingAs(Canary::partnerFor($client->id));

    // Livewire's test harness turns the 403 of the boot guard into the response status, as for every relation manager.
    Livewire::test(ClientKnowledgeRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])->assertForbidden();
    Livewire::test(ProjectKnowledgeRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])->assertForbidden();
});

it('answers 403 to a Partner on the client page and shows no canary title of an article of the own client', function (): void {
    [$client, $project] = knowledgeTabsIsolationOwners();
    KnowledgeFixtures::article(['title' => Canary::canary('knowledge_tab'), 'client_id' => $client->id, 'project_id' => $project->id]);

    $this->actingAs(Canary::partnerFor($client->id));

    $content = (string) $this->get(ClientResource::getUrl('view', ['record' => $client->getKey()]))->assertForbidden()->getContent();

    expect($content)->not->toContain('CANARY_knowledge_tab');
});
