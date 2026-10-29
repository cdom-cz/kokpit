<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TaskResource;
use Filament\Facades\Filament;
use Tests\Support\Canary;

/*
 * The panel-wide global search: the Admin finds clients by name, projects by key or name
 * and tasks by reference or title, grouped in the sidebar order; a Partner finds nothing.
 * Every name and key is fictional.
 */

/**
 * The categories of the panel's global search for a term, label => list of [title, details, url].
 *
 * @return array<string, list<array{title: string, details: array<string, string>, url: string}>>
 */
function gsCategories(string $term): array
{
    $results = Filament::getCurrentOrDefaultPanel()->getGlobalSearchProvider()?->getResults($term);
    $categories = [];

    foreach ($results?->getCategories() ?? [] as $label => $items) {
        foreach ($items as $item) {
            $categories[$label][] = [
                'title' => (string) $item->title,
                'details' => array_map('strval', $item->details),
                'url' => $item->url,
            ];
        }
    }

    return $categories;
}

/**
 * A project of the client written through the domain Action.
 */
function gsProject(Client $client, string $key, string $name, bool $visible = false): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => $visible,
    ]);
}

/**
 * The resources of the panel that are globally searchable for the signed-in user, sorted.
 *
 * @return list<class-string>
 */
function gsSearchable(): array
{
    $searchable = array_values(array_filter(
        Filament::getResources(),
        static fn (string $resource): bool => $resource::canGloballySearch(),
    ));

    sort($searchable);

    return $searchable;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('finds a client by a part of its name with the stage as details and the view page as link', function (): void {
    $client = Client::factory()->create(['name' => 'Example Northwind client']);

    $categories = gsCategories('northwind');

    expect(array_keys($categories))->toBe(['Klienti'])
        ->and($categories['Klienti'])->toHaveCount(1)
        ->and($categories['Klienti'][0]['title'])->toBe('Example Northwind client')
        ->and(array_values($categories['Klienti'][0]['details']))->toBe([$client->stage->getLabel()])
        ->and($categories['Klienti'][0]['details'])->not->toHaveKey((string) __('kokpit.clients.fields.hourly_rate'))
        ->and($categories['Klienti'][0]['url'])->toBe(ClientResource::getUrl('view', ['record' => $client]));
});

it('shows the company number of a client in the details only when it is filled', function (): void {
    $client = Client::factory()->create(['name' => 'Example Filled client', 'company_number' => '12345678']);

    $details = gsCategories('filled')['Klienti'][0]['details'] ?? [];

    expect($details)->toBe([
        (string) __('kokpit.clients.fields.company_number') => '12345678',
        (string) __('kokpit.clients.fields.stage') => $client->stage->getLabel(),
    ]);
});

it('finds a project by its key in any letter case with the client and status as details', function (): void {
    $client = Client::factory()->create(['name' => 'Example Holder client']);
    $project = gsProject($client, 'NWD', 'Example northwind website');

    foreach (['nwd', 'NWD', 'Nwd'] as $term) {
        $categories = gsCategories($term);

        expect(array_keys($categories))->toBe(['Projekty'])
            ->and($categories['Projekty'])->toHaveCount(1)
            ->and($categories['Projekty'][0]['title'])->toBe('NWD · Example northwind website')
            ->and(array_values($categories['Projekty'][0]['details']))->toBe(['Example Holder client', $project->status->getLabel()])
            ->and($categories['Projekty'][0]['url'])->toBe(ProjectResource::getUrl('view', ['record' => $project]));
    }
});

it('finds a project by a word of its name, also when several words are typed', function (): void {
    gsProject(Client::factory()->create(), 'NWD', 'Example northwind website');

    expect(gsCategories('website')['Projekty'] ?? [])->toHaveCount(1)
        ->and(gsCategories('northwind website')['Projekty'] ?? [])->toHaveCount(1);
});

it('leaves an archived client and an archived project out of the global search', function (): void {
    $keptClient = Client::factory()->create(['name' => 'Example Kept client']);
    $archivedClient = Client::factory()->create(['name' => 'Example Archived client']);
    $keptProject = gsProject($keptClient, 'KEP', 'Example kept project');
    $archivedProject = gsProject($keptClient, 'ARC', 'Example archived project');

    $archivedClient->delete();
    $archivedProject->delete();

    expect(ClientResource::getGlobalSearchEloquentQuery()->pluck('clients.id')->all())->toBe([$keptClient->id])
        ->and(ProjectResource::getGlobalSearchEloquentQuery()->pluck('projects.id')->all())->toBe([$keptProject->id])
        ->and(gsCategories('archived'))->toBe([]);
});

it('treats a percent sign and an underscore in the term as plain characters', function (): void {
    gsProject(Client::factory()->create(['name' => 'Example Wildcard client']), 'WLD', 'Example wildcard project');

    expect(gsCategories('%'))->toBe([])
        ->and(gsCategories('_'))->toBe([]);
});

it('groups the matches of one term under clients, projects and tasks and never under activity', function (): void {
    $client = Client::factory()->create(['name' => 'Example group client']);
    $project = gsProject($client, 'GRP', 'Example group project');
    app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example group task']);

    $categories = gsCategories('Example');

    expect($categories)->toHaveKeys(['Klienti', 'Projekty', 'Úkoly'])
        ->and($categories)->not->toHaveKey('Aktivita');
});

it('lets exactly the Admin-only client, project and task resources be globally searchable', function (): void {
    $expected = [ClientResource::class, ProjectResource::class, TaskResource::class];
    sort($expected);

    expect(gsSearchable())->toBe($expected);

    foreach ($expected as $resource) {
        expect(AccessRules::for($resource)?->audience)->toBe(Audience::AdminOnly);
    }

    $this->actingAs(Canary::partnerFor(Client::factory()->create()->id));

    expect(gsSearchable())->toBe([]);
});

it('returns a Partner no global search result for the own client, project or task', function (): void {
    $client = Client::factory()->create(['name' => 'Example Partner owned client']);
    $project = gsProject($client, 'PVS', 'Example partner visible project', true);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example partner task']);

    $terms = ['partner owned', 'PVS', 'visible project', $task->reference, 'Example'];

    // The control: the Admin finds each term, so the empty Partner answer is the rule and not an empty index.
    foreach ($terms as $term) {
        expect(gsCategories($term))->not->toBe([]);
    }

    $this->actingAs(Canary::partnerFor($client->id));

    foreach ($terms as $term) {
        expect(gsCategories($term))->toBe([]);
    }

    expect(ClientResource::canGloballySearch())->toBeFalse()
        ->and(ProjectResource::canGloballySearch())->toBeFalse()
        ->and(TaskResource::canGloballySearch())->toBeFalse();
});

it('lists the groups in the sidebar order: clients, projects, tasks', function (): void {
    $client = Client::factory()->create(['name' => 'Example order client']);
    $project = gsProject($client, 'ORD', 'Example order project');
    app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example order task']);

    expect(array_keys(gsCategories('Example')))->toBe(['Klienti', 'Projekty', 'Úkoly']);
});

it('orders clients by name and projects by key inside their group', function (): void {
    $beta = Client::factory()->create(['name' => 'Example Beta client']);
    Client::factory()->create(['name' => 'Example Alpha client']);
    gsProject($beta, 'OTH', 'Example other project');
    gsProject($beta, 'NWD', 'Example northwind project');

    $categories = gsCategories('Example');

    expect(array_column($categories['Klienti'], 'title'))->toBe(['Example Alpha client', 'Example Beta client'])
        ->and(array_column($categories['Projekty'], 'title'))->toBe(['NWD · Example northwind project', 'OTH · Example other project']);
});

it('lists the active tasks before the Done ones inside the tasks group, newest first in each part', function (): void {
    $project = gsProject(Client::factory()->create(['name' => 'Example task order client']), 'TOR', 'Example task order project');
    $one = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example search task one']);
    $two = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example search task two']);
    $three = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example search task three']);
    $four = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example search task four']);

    app(UpdateTask::class)->handle($this->admin, $one, ['status' => 'done']);
    app(UpdateTask::class)->handle($this->admin, $three, ['status' => 'done']);

    $categories = gsCategories('Example search task');

    expect(array_column($categories['Úkoly'], 'title'))->toBe([
        $four->reference.' · Example search task four',
        $two->reference.' · Example search task two',
        $three->reference.' · Example search task three',
        $one->reference.' · Example search task one',
    ])
        ->and(array_keys(gsCategories('Example')))->toBe(['Klienti', 'Projekty', 'Úkoly']);
});
