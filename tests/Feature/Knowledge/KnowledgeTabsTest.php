<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Tags\TagType;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ClientKnowledgeRelationManager;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectKnowledgeRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The "Znalosti" tab of the Admin client page (ORG-04, D-07): the general articles linked to the client, those of
 * its projects included, without ever loading a body. Helpers carry the prefix `knowledgeTabs`, because Pest loads
 * every test file into one namespace. Clients, projects and articles are fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

/**
 * Mounts the knowledge tab of a client as the Admin sees it on the read-only detail page.
 */
function knowledgeTabsClientTab(Client $client): Testable
{
    return Livewire::test(ClientKnowledgeRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);
}

/**
 * Mounts the knowledge tab of a project as the Admin sees it on the read-only detail page.
 */
function knowledgeTabsProjectTab(Project $project): Testable
{
    return Livewire::test(ProjectKnowledgeRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class]);
}

/**
 * A fictional project of the client.
 */
function knowledgeTabsProject(Client $client): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => Project::factory()->create(['client_id' => $client->id]));
}

/**
 * A fictional client.
 */
function knowledgeTabsClient(): Client
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create());
}

/**
 * A moment as it is stored: UTC.
 */
function knowledgeTabsMoment(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

it('registers the knowledge tab among the relations of the client resource', function (): void {
    expect(ClientResource::getRelations())->toContain(ClientKnowledgeRelationManager::class);
});

it('lists the articles of the client and of its projects and leaves out other clients, unlinked articles and incidents', function (): void {
    $client = knowledgeTabsClient();
    $other = knowledgeTabsClient();
    $project = knowledgeTabsProject($client);
    $direct = KnowledgeFixtures::article(['title' => 'Example direct article', 'client_id' => $client->id]);
    $viaProject = KnowledgeFixtures::article(['title' => 'Example project article', 'client_id' => $client->id, 'project_id' => $project->id]);
    $foreign = KnowledgeFixtures::article(['title' => 'Example foreign article', 'client_id' => $other->id]);
    $unlinked = KnowledgeFixtures::article(['title' => 'Example unlinked article']);
    $incident = KnowledgeFixtures::article(['title' => 'Example incident article', 'client_id' => $client->id, 'kind' => ArticleKind::Incident]);

    knowledgeTabsClientTab($client)
        ->assertCanSeeTableRecords([$direct, $viaProject])
        ->assertCanNotSeeTableRecords([$foreign, $unlinked, $incident]);
});

it('shows the project, the version, the last edit, only the knowledge tags and the lock of a sensitive article', function (): void {
    $client = knowledgeTabsClient();
    $project = knowledgeTabsProject($client);
    $article = KnowledgeFixtures::taggedArticle(['example-tag-one', 'example-tag-two'], [
        'title' => 'Example sensitive article',
        'client_id' => $client->id,
        'project_id' => $project->id,
        'version_no' => 2,
        'sensitive' => true,
    ]);
    app(PartnerContext::class)->runAsSystem(static function () use ($article): void {
        $article->attachTags(['example-client-tag'], TagType::Client->value);
    });

    knowledgeTabsClientTab($client)
        ->assertCanSeeTableRecords([$article])
        ->assertSee($project->key.' · '.$project->name)
        ->assertSee('example-tag-one')
        ->assertSee('example-tag-two')
        ->assertDontSee('example-client-tag')
        ->assertTableColumnStateSet('version_no', 2, $article)
        ->assertTableColumnStateSet('sensitive', true, $article);
});

it('links the title of a row to the view screen of the article', function (): void {
    $client = knowledgeTabsClient();
    $article = KnowledgeFixtures::article(['title' => 'Example linked article', 'client_id' => $client->id]);

    knowledgeTabsClientTab($client)
        ->assertSee(KnowledgeArticleResource::getUrl('view', ['record' => $article]), false);
});

it('sorts the rows by the last edit, newest first', function (): void {
    $client = knowledgeTabsClient();
    $older = KnowledgeFixtures::article(['title' => 'Example older article', 'client_id' => $client->id, 'edited_at' => knowledgeTabsMoment('2026-11-20 10:00:00')]);
    $newer = KnowledgeFixtures::article(['title' => 'Example newer article', 'client_id' => $client->id, 'edited_at' => knowledgeTabsMoment('2026-11-24 12:30:00')]);

    knowledgeTabsClientTab($client)->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('never selects the body of an article on the tab', function (): void {
    $client = knowledgeTabsClient();
    KnowledgeFixtures::article(['title' => 'Example bodied article', 'body_md' => 'Example body text', 'client_id' => $client->id]);

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'knowledge_articles')) {
            $queries[] = $query->sql;
        }
    });

    knowledgeTabsClientTab($client)->assertSee('Example bodied article')->assertDontSee('Example body text');

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $sql) {
        expect($sql)->not->toContain('body_md');
    }
});

it('registers the knowledge tab among the relations of the project resource', function (): void {
    expect(ProjectResource::getRelations())->toContain(ProjectKnowledgeRelationManager::class);
});

it('lists on the project tab only the articles of that project, and the client-only article on the client tab', function (): void {
    $client = knowledgeTabsClient();
    $project = knowledgeTabsProject($client);
    $sibling = knowledgeTabsProject($client);
    $ofProject = KnowledgeFixtures::article(['title' => 'Example project article', 'client_id' => $client->id, 'project_id' => $project->id]);
    $ofSibling = KnowledgeFixtures::article(['title' => 'Example sibling article', 'client_id' => $client->id, 'project_id' => $sibling->id]);
    $clientOnly = KnowledgeFixtures::article(['title' => 'Example client article', 'client_id' => $client->id]);
    $incident = KnowledgeFixtures::article(['title' => 'Example incident article', 'client_id' => $client->id, 'project_id' => $project->id, 'kind' => ArticleKind::Incident]);

    knowledgeTabsProjectTab($project)
        ->assertCanSeeTableRecords([$ofProject])
        ->assertCanNotSeeTableRecords([$ofSibling, $clientOnly, $incident]);

    knowledgeTabsClientTab($client)->assertCanSeeTableRecords([$ofProject, $ofSibling, $clientOnly]);
});

it('hides archived articles until the switch is on and then lists them, on both tabs', function (): void {
    $client = knowledgeTabsClient();
    $project = knowledgeTabsProject($client);
    $active = KnowledgeFixtures::article(['title' => 'Example active article', 'client_id' => $client->id, 'project_id' => $project->id]);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article', 'client_id' => $client->id, 'project_id' => $project->id]);

    foreach ([knowledgeTabsClientTab($client), knowledgeTabsProjectTab($project)] as $tab) {
        $tab->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived])
            ->filterTable('show_archived')
            ->assertCanSeeTableRecords([$active, $archived]);
    }
});

it('offers Přidat článek on both read-only pages with the client and the project in the create URL', function (): void {
    $client = knowledgeTabsClient();
    $project = knowledgeTabsProject($client);

    knowledgeTabsClientTab($client)
        ->assertActionVisible(TestAction::make('addArticle')->table())
        ->assertActionHasUrl(TestAction::make('addArticle')->table(), KnowledgeArticleResource::getUrl('create', ['client' => $client->id]));

    knowledgeTabsProjectTab($project)
        ->assertActionVisible(TestAction::make('addArticle')->table())
        ->assertActionHasUrl(TestAction::make('addArticle')->table(), KnowledgeArticleResource::getUrl('create', ['client' => $client->id, 'project' => $project->id]));
});

it('shows an empty tab with its heading, its own description and the add action', function (): void {
    $client = knowledgeTabsClient();
    $project = knowledgeTabsProject($client);

    knowledgeTabsClientTab($client)
        ->assertSee(__('knowledge.links.tab.empty_heading'))
        ->assertSee(__('knowledge.links.tab.empty_client'))
        ->assertSee(__('knowledge.links.tab.add'));

    knowledgeTabsProjectTab($project)
        ->assertSee(__('knowledge.links.tab.empty_heading'))
        ->assertSee(__('knowledge.links.tab.empty_project'))
        ->assertSee(__('knowledge.links.tab.add'));
});

it('shows the project tab no body and no tag of another type', function (): void {
    $client = knowledgeTabsClient();
    $project = knowledgeTabsProject($client);
    $article = KnowledgeFixtures::taggedArticle(['example-tag-one'], [
        'title' => 'Example tabbed article',
        'body_md' => 'Example body text',
        'client_id' => $client->id,
        'project_id' => $project->id,
    ]);
    app(PartnerContext::class)->runAsSystem(static function () use ($article): void {
        $article->attachTags(['example-project-tag'], TagType::Project->value);
    });

    knowledgeTabsProjectTab($project)
        ->assertCanSeeTableRecords([$article])
        ->assertSee('example-tag-one')
        ->assertDontSee('example-project-tag')
        ->assertDontSee('Example body text');
});
