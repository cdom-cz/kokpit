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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

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

/**
 * The field errors of a callable that must throw a ValidationException.
 *
 * @return array<string, list<string>>
 */
function subtaskErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('offers no subtasks tab and no create-subtask action on the page of a subtask', function (): void {
    $project = subtaskProject();
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $subtask = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example leaf subtask'], $parent);

    expect(SubtasksRelationManager::canViewForRecord($parent, ViewTask::class))->toBeTrue()
        ->and(SubtasksRelationManager::canViewForRecord($subtask, ViewTask::class))->toBeFalse();

    $this->get('/admin/tasks/ABC-1')->assertOk()->assertSee(__('kokpit.tasks.subtasks.relation_title'));
    $this->get('/admin/tasks/ABC-2')->assertOk()
        ->assertDontSee(__('kokpit.tasks.subtasks.relation_title'))
        ->assertDontSee(__('kokpit.tasks.subtasks.actions.create'));
});

it('refuses a subtask as the parent of a subtask with the parent field error and uses no number', function (): void {
    $project = subtaskProject();
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $subtask = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example leaf subtask'], $parent);

    $errors = subtaskErrors(fn () => app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example sub-subtask'], $subtask));

    expect($errors)->toHaveKey('parent')
        ->and($errors['parent'])->toBe([__('kokpit.tasks.errors.parent_is_subtask')])
        ->and(Task::query()->count())->toBe(2);

    $next = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example next task']);

    expect($next->reference)->toBe('ABC-3');
});

it('refuses a parent from another project with the parent field error', function (): void {
    $parent = app(CreateTask::class)->handle($this->admin, subtaskProject('ABC'), ['title' => 'Example parent task']);
    $other = subtaskProject('XYZ');

    $errors = subtaskErrors(fn () => app(CreateTask::class)->handle($this->admin, $other, ['title' => 'Example stray subtask'], $parent));

    expect($errors['parent'] ?? [])->toBe([__('kokpit.tasks.errors.parent_other_project')])
        ->and(Task::query()->where('project_id', $other->id)->count())->toBe(0);
});

it('refuses an archived parent with the parent field error', function (): void {
    $project = subtaskProject();
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $parent->delete();

    $errors = subtaskErrors(fn () => app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example orphan subtask'], $parent));

    expect($errors['parent'] ?? [])->toBe([__('kokpit.tasks.errors.parent_archived')])
        ->and(Task::query()->withTrashed()->count())->toBe(1);
});

it('refuses a parent that does not exist with the parent field error', function (): void {
    $project = subtaskProject();
    $ghost = new Task;
    $ghost->forceFill(['id' => (string) Str::uuid7()]);

    $errors = subtaskErrors(fn () => app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example ghost subtask'], $ghost));

    expect($errors['parent'] ?? [])->toBe([__('kokpit.tasks.errors.parent_unavailable')])
        ->and(Task::query()->withTrashed()->count())->toBe(0);
});

it('shows the parent error under the title field of the subtask modal', function (): void {
    $project = subtaskProject();
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $parent->delete();

    Livewire::test(SubtasksRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), ['title' => 'Example refused subtask'])
        ->assertHasActionErrors(['title']);

    expect(Task::query()->withTrashed()->count())->toBe(1);
});

it('refuses a Partner that passes a parent, they create top-level tasks only', function (): void {
    $client = Client::factory()->create();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Example partner project',
        'key' => 'PRT',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);

    $this->actingAs(Canary::partnerFor($client->id));
    $partner = auth()->user();

    $errors = subtaskErrors(fn () => app(CreateTask::class)->handle($partner, $project, ['title' => 'Example partner subtask'], $parent));

    expect($errors['parent'] ?? [])->toBe([__('kokpit.tasks.errors.parent_not_allowed')]);

    $this->actingAs($this->admin);

    expect(Task::query()->count())->toBe(1);
});

it('numbers a task, its subtask and the next task 1, 2 and 3 from one counter', function (): void {
    $project = subtaskProject();
    $first = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example first task']);
    $sub = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example middle subtask'], $first);
    $third = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example third task']);

    expect([$first->reference, $sub->reference, $third->reference])->toBe(['ABC-1', 'ABC-2', 'ABC-3'])
        ->and([$first->depth, $sub->depth, $third->depth])->toBe([0, 1, 0]);
});

it('lets the database refuse a sub-subtask and a cross-project subtask even when the Action is bypassed', function (): void {
    $project = subtaskProject('ABC');
    $other = subtaskProject('XYZ');
    $parent = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example parent task']);
    $subtask = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example leaf subtask'], $parent);

    $forge = static function (Project $in, Task $under, int $number) use ($parent): Task {
        $task = new Task(['title' => 'Example forged task']);
        $task->forceFill([
            'project_id' => $in->id,
            'parent_id' => $under->id,
            'depth' => 1,
            'number' => $number,
            'reference' => $in->key.'-'.$number,
            'position' => 90 + $number,
            'assignee_id' => $parent->assignee_id,
            'requester_id' => $parent->requester_id,
        ]);

        return $task;
    };

    RawSql::expectSqlState('23503', fn () => $forge($project, $subtask, 70)->save());
    RawSql::expectSqlState('23503', fn () => $forge($other, $parent, 71)->save());
});
