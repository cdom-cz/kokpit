<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The header actions of the project view (D-07, D-12): the New task action with the
 * project locked and the Change status action. Every name and key is fictional.
 */

/**
 * A project with the given key, written through the domain Action.
 *
 * @param  array<string, mixed>  $overrides
 */
function projViewProject(string $key = 'PVA', ?Client $client = null, array $overrides = []): Project
{
    return app(CreateProject::class)->handle($client ?? Client::factory()->create(), [
        'name' => 'Example view project',
        'key' => $key,
        'billing_type' => 'hourly',
        ...$overrides,
    ]);
}

/**
 * The view page of the project as the Admin sees it.
 */
function projViewPage(Project $project): Testable
{
    return Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('creates a task from the project view with only a title and opens its edit page', function (): void {
    $project = projViewProject();

    projViewPage($project)
        ->assertActionVisible('quickCreate')
        ->callAction('quickCreate', ['title' => 'Example locked task'])
        ->assertHasNoActionErrors()
        ->assertRedirect('/admin/tasks/PVA-1/edit');

    $task = Task::query()->where('reference', 'PVA-1')->firstOrFail();

    expect($task->title)->toBe('Example locked task')
        ->and($task->project_id)->toBe($project->id);
});

it('ignores a project id sent with the locked modal', function (): void {
    $project = projViewProject();
    $other = projViewProject('PVB');

    projViewPage($project)
        ->callAction('quickCreate', ['project_id' => $other->id, 'title' => 'Example forged task'])
        ->assertRedirect('/admin/tasks/PVA-1/edit');

    expect(Task::query()->where('reference', 'PVA-1')->firstOrFail()->project_id)->toBe($project->id)
        ->and(Task::query()->where('project_id', $other->id)->count())->toBe(0);
});

it('shows the project disabled with the explanation in the locked modal', function (): void {
    $project = projViewProject();

    $component = projViewPage($project)->mountAction('quickCreate');
    $html = $component->instance()->getMountedAction()->getModalContent()->render();

    expect($html)->toContain('Projekt je daný detailem projektu.')
        ->and($html)->toContain('PVA · Example view project');
});

it('hides the New task action on a Done project', function (): void {
    $project = projViewProject();
    app(UpdateProject::class)->handle($project, ['status' => 'done']);

    projViewPage($project)->assertActionHidden('quickCreate');
});

it('hides the New task action on an archived project', function (): void {
    $project = projViewProject();
    $project->delete();

    projViewPage($project)->assertActionHidden('quickCreate');
});

it('hides the New task action on a project of an archived client', function (): void {
    $client = Client::factory()->create();
    $project = projViewProject('PVC', $client);
    $client->delete();

    projViewPage($project)->assertActionHidden('quickCreate');
});
