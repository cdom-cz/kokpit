<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\SubtasksRelationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * One-level subtasks (TA-01, D-03, D-11): created from the task page, numbered from
 * the same project counter, with their own page and an independent status. Every
 * name and key is fictional.
 */

/**
 * A project with the given key, written through the domain Action.
 */
function subtaskProject(string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example subtask project',
        'key' => $key,
        'billing_type' => 'hourly',
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('creates a subtask from the task page as the next number of the project and continues on its edit page', function (): void {
    $parent = app(CreateTask::class)->handle($this->admin, subtaskProject(), ['title' => 'Example parent task']);

    Livewire::test(SubtasksRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), ['title' => 'Example subtask one'])
        ->assertHasNoActionErrors()
        ->assertRedirect('/admin/tasks/ABC-2/edit');

    $subtask = Task::query()->where('reference', 'ABC-2')->firstOrFail();

    expect($subtask->parent_id)->toBe($parent->id)
        ->and($subtask->depth)->toBe(1)
        ->and($subtask->number)->toBe(2)
        ->and($subtask->project_id)->toBe($parent->project_id)
        ->and($subtask->title)->toBe('Example subtask one');
});

it('lists the subtasks on the page of the parent task', function (): void {
    $parent = app(CreateTask::class)->handle($this->admin, subtaskProject(), ['title' => 'Example parent task']);
    app(CreateTask::class)->handle($this->admin, Project::query()->findOrFail($parent->project_id), ['title' => 'Example listed subtask'], $parent);

    Livewire::test(SubtasksRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => ViewTask::class])
        ->assertSee('ABC-2')
        ->assertSee('Example listed subtask');
});

it('opens a subtask at its own address and links back to the parent', function (): void {
    $parent = app(CreateTask::class)->handle($this->admin, subtaskProject(), ['title' => 'Example parent task']);
    $subtask = app(CreateTask::class)->handle($this->admin, Project::query()->findOrFail($parent->project_id), ['title' => 'Example linked subtask'], $parent);

    $this->get('/admin/tasks/ABC-2')->assertOk()->assertSee('Example linked subtask');

    Livewire::test(ViewTask::class, ['record' => $subtask->reference])
        ->assertSee('ABC-1')
        ->assertSee(TaskResource::getUrl('view', ['record' => $parent]), escape: false);
});

it('keeps the status of a subtask and of its parent independent of each other', function (): void {
    $project = subtaskProject();
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $subtask = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example independent subtask'], $parent);

    Livewire::test(EditTask::class, ['record' => $subtask->reference])
        ->fillForm(['status' => 'done'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subtask->refresh()->status->value)->toBe('done')
        ->and($parent->refresh()->status->value)->toBe('planned');

    app(UpdateTask::class)->handle($this->admin, $parent, ['status' => 'in_progress']);

    expect($parent->refresh()->status->value)->toBe('in_progress')
        ->and($subtask->refresh()->status->value)->toBe('done');
});

it('shows the attachments section with the note and offers no upload', function (): void {
    $task = app(CreateTask::class)->handle($this->admin, subtaskProject(), ['title' => 'Example attachments task']);

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertSee(__('kokpit.tasks.attachments.heading'))
        ->assertSee(__('kokpit.tasks.attachments.later'))
        ->assertDontSeeHtml('type="file"');
});
