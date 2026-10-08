<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin task screens (D-09, TA-02): the one-modal quick creation, the task page
 * at the stable address /admin/tasks/KEY-N, and the refusal of every Admin task route
 * to a Partner. Every name and key is fictional.
 */

/**
 * A project with the given key, written through the domain Action.
 */
function taskResProject(string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example task screen project',
        'key' => $key,
        'billing_type' => 'hourly',
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('creates a task from the quick modal with only a project and a title and continues on its edit page', function (): void {
    $project = taskResProject();

    Livewire::test(ListTasks::class)
        ->callAction('quickCreate', ['project_id' => $project->id, 'title' => 'Example quick task'])
        ->assertHasNoActionErrors()
        ->assertRedirect('/admin/tasks/ABC-1/edit');

    $task = Task::query()->where('reference', 'ABC-1')->firstOrFail();

    expect($task->title)->toBe('Example quick task')
        ->and($task->project_id)->toBe($project->id)
        ->and($task->requester_id)->toBe($this->admin->id)
        ->and($task->assignee_id)->toBe($this->admin->id)
        ->and(TaskResource::getUrl('view', ['record' => $task]))->toEndWith('/admin/tasks/ABC-1');
});

it('shows the reference and the title on the task page', function (): void {
    $task = app(CreateTask::class)->handle($this->admin, taskResProject(), ['title' => 'Example visible task']);

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertSee('ABC-1')
        ->assertSee('Example visible task');

    $this->get('/admin/tasks/ABC-1')->assertOk()->assertSee('Example visible task');
});

it('opens the same task at a lower-case key', function (): void {
    app(CreateTask::class)->handle($this->admin, taskResProject(), ['title' => 'Example lower case task']);

    $this->get('/admin/tasks/abc-1')->assertOk()->assertSee('Example lower case task');
    $this->get('/admin/tasks/abc-2')->assertNotFound();
});

it('answers the project field when the project is archived', function (): void {
    $project = taskResProject();
    $project->delete();

    Livewire::test(ListTasks::class)
        ->callAction('quickCreate', ['project_id' => $project->id, 'title' => 'Example refused task'])
        ->assertHasActionErrors(['project_id']);

    expect(Task::query()->count())->toBe(0);
});

it('requires a title in the quick modal', function (): void {
    $project = taskResProject();

    Livewire::test(ListTasks::class)
        ->callAction('quickCreate', ['project_id' => $project->id, 'title' => ''])
        ->assertHasActionErrors(['title' => 'required']);
});

it('refuses a Partner the list and the page of an own-client task', function (): void {
    $client = Client::factory()->create();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Example visible project',
        'key' => 'VIS',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example own task']);

    $this->actingAs(Canary::partnerFor($client->id));

    $this->get('/admin/tasks')->assertForbidden();
    $this->get('/admin/tasks/'.$task->reference)->assertForbidden();
});

/**
 * The categories of the panel's global search for a term, label => list of [title, details].
 *
 * @return array<string, list<array{title: string, details: array<string, string>}>>
 */
function taskResSearch(string $term): array
{
    $results = Filament::getCurrentOrDefaultPanel()->getGlobalSearchProvider()?->getResults($term);
    $categories = [];

    foreach ($results?->getCategories() ?? [] as $label => $items) {
        foreach ($items as $item) {
            $categories[$label][] = ['title' => (string) $item->title, 'details' => array_map('strval', $item->details)];
        }
    }

    return $categories;
}

it('finds a task by its key in the global search with the project key and status as details', function (): void {
    app(CreateTask::class)->handle($this->admin, taskResProject(), ['title' => 'Example searchable task']);

    $categories = taskResSearch('ABC-1');

    expect(array_keys($categories))->toBe(['Úkoly'])
        ->and($categories['Úkoly'])->toHaveCount(1)
        ->and($categories['Úkoly'][0]['title'])->toBe('ABC-1 · Example searchable task')
        ->and(array_values($categories['Úkoly'][0]['details']))->toBe(['ABC', 'Plánovaný']);
});

it('finds a task by a word of its title in the global search', function (): void {
    app(CreateTask::class)->handle($this->admin, taskResProject(), ['title' => 'Example needle task']);

    expect(taskResSearch('needle')['Úkoly'] ?? [])->toHaveCount(1);
});

it('shows no project, client or activity result in the global search', function (): void {
    $project = taskResProject();
    app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example other task']);

    // The project name, the project key and the client name all match something.
    expect(array_keys(taskResSearch('Example')))->toBe(['Úkoly'])
        ->and(taskResSearch('ABC'))->not->toHaveKeys(['Projekty', 'Klienti', 'Aktivita']);
});

it('returns a Partner no global search result for the reference of an own-client task', function (): void {
    $client = Client::factory()->create();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Example partner visible project',
        'key' => 'PVP',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
    $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example partner task']);

    // The control: the Admin finds the very same task, so the empty Partner answer is the rule and not an empty index.
    expect(taskResSearch($task->reference))->toHaveKey('Úkoly');

    $this->actingAs(Canary::partnerFor($client->id));

    expect(taskResSearch($task->reference))->toBe([])
        ->and(taskResSearch('Example'))->toBe([]);
});

it('lets exactly one resource of the panel be globally searchable, the Admin-only TaskResource', function (): void {
    $searchable = array_values(array_filter(
        Filament::getResources(),
        static fn (string $resource): bool => $resource::canGloballySearch(),
    ));

    expect($searchable)->toBe([TaskResource::class]);

    $this->actingAs(Canary::partnerFor(Client::factory()->create()->id));

    expect(array_values(array_filter(
        Filament::getResources(),
        static fn (string $resource): bool => $resource::canGloballySearch(),
    )))->toBe([]);
});
