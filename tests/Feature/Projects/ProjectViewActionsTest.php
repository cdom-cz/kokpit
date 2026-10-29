<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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

it('never lets a project id sent with the locked modal decide the project', function (): void {
    $project = projViewProject();
    $other = projViewProject('PVB');

    // A forged id is refused by the select's own option check; the action body never reads it either way.
    projViewPage($project)
        ->callAction('quickCreate', ['project_id' => $other->id, 'title' => 'Example forged task'])
        ->assertHasActionErrors(['project_id']);

    expect(Task::query()->count())->toBe(0);

    projViewPage($project)
        ->callAction('quickCreate', ['project_id' => $project->id, 'title' => 'Example honest task'])
        ->assertHasNoActionErrors();

    expect(Task::query()->where('project_id', $other->id)->count())->toBe(0)
        ->and(Task::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('shows the project disabled with the explanation in the locked modal', function (): void {
    $project = projViewProject();

    expect(__('kokpit.tasks.hints.project_locked'))->toBe('Projekt je daný detailem projektu.');

    projViewPage($project)
        ->mountAction('quickCreate')
        ->assertSchemaStateSet(['project_id' => $project->id])
        ->assertSchemaComponentExists(
            'project_id',
            'mountedActionSchema0',
            static fn (Select $field): bool => $field->isDisabled()
                && array_values($field->getOptions()) === ['PVA · Example view project'],
        );
});

it('lists the header actions of the project view in order', function (): void {
    $project = projViewProject();
    $visible = static fn (Testable $page): array => collect($page->instance()->getCachedHeaderActions())
        ->filter(static fn ($action): bool => $action instanceof Action && $action->isVisible())
        ->map(static fn (Action $action): string => (string) $action->getName())
        ->values()
        ->all();

    expect($visible(projViewPage($project)))->toBe(['board', 'quickCreate', 'changeStatus', 'edit', 'delete']);

    $project->delete();

    expect($visible(projViewPage($project)))->toContain('restore')
        ->not->toContain('quickCreate')
        ->not->toContain('changeStatus')
        ->not->toContain('delete');
});

it('changes the status from the project view with the effect of the edit form', function (): void {
    $project = projViewProject('PVS', null, ['tags' => ['alpha']]);
    $project->activitiesAsSubject()->delete();
    $before = Project::query()->with('billing')->findOrFail($project->id);

    $component = projViewPage($project)
        ->assertActionVisible('changeStatus')
        ->mountAction('changeStatus')
        ->assertSchemaStateSet(['status' => ProjectStatus::Planned])
        ->setActionData(['status' => 'done'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Stav projektu byl změněn');

    $after = Project::query()->with('billing')->findOrFail($project->id);
    $rows = $project->activitiesAsSubject()->get();
    $changes = $rows->first()?->attribute_changes;

    expect($after->status->value)->toBe('done')
        ->and($after->name)->toBe($before->name)
        ->and($after->key)->toBe($before->key)
        ->and($after->client_visible)->toBe($before->client_visible)
        ->and($after->tagsWithType('project')->pluck('name')->all())->toBe(['alpha'])
        ->and($after->billing?->hourly_rate)->toEqual($before->billing?->hourly_rate)
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()?->causer_id)->toBe($this->admin->id)
        ->and($changes?->get('old')['status'] ?? null)->toBe('planned')
        ->and($changes?->get('attributes')['status'] ?? null)->toBe('done');

    $component->assertFormSet(['status' => ProjectStatus::Done]);
});

it('answers an empty status with an error under the field and changes nothing', function (): void {
    $project = projViewProject();

    projViewPage($project)
        ->mountAction('changeStatus')
        ->setActionData(['status' => ''])
        ->callMountedAction()
        ->assertHasActionErrors(['status']);

    expect(Project::query()->findOrFail($project->id)->status->value)->toBe('planned');
});

it('hides Change status on an archived project', function (): void {
    $project = projViewProject();
    $project->delete();

    projViewPage($project)->assertActionHidden('changeStatus');
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
