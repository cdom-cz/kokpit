<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\RelationManagers\TaskHistoryRelationManager;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin edits a task (TA-01): the edit page, the UpdateTask Action, the locked
 * status change that re-positions the task, and the audited history. Every name and
 * key is fictional.
 */

/**
 * A project with the given key, written through the domain Action.
 */
function taskUpdProject(string $key = 'UPD', bool $visible = false): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example task update project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => $visible,
    ]);
}

/**
 * A task created through the Action as the signed-in Admin.
 *
 * @param  array<string, mixed>  $data
 */
function taskUpdTask(Project $project, array $data = []): Task
{
    return app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example update task', ...$data]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('opens the edit page of a task at its stable address', function (): void {
    taskUpdTask(taskUpdProject());

    $this->get('/admin/tasks/UPD-1/edit')->assertOk()->assertSee('Example update task');
    $this->get('/admin/tasks/upd-1/edit')->assertOk();
    expect(TaskResource::getUrl('edit', ['record' => 'UPD-1']))->toEndWith('/admin/tasks/UPD-1/edit');
});

it('offers the edit action on the task page', function (): void {
    taskUpdTask(taskUpdProject());

    Livewire::test(ViewTask::class, ['record' => 'UPD-1'])->assertActionExists('edit');
});

it('changes the title and moves the task to the end of the new status column', function (): void {
    $project = taskUpdProject();
    taskUpdTask($project, ['status' => 'in_progress']);
    taskUpdTask($project, ['status' => 'in_progress']);
    $task = taskUpdTask($project);

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['title' => 'Example renamed task', 'status' => 'in_progress'])
        ->call('save')
        ->assertHasNoFormErrors();

    $task->refresh();

    expect($task->title)->toBe('Example renamed task')
        ->and($task->status)->toBe(ProjectStatus::InProgress)
        ->and($task->completed_at)->toBeNull()
        ->and($task->position)->toBe(2);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('sets completed_at when the status becomes Done and clears it when it changes back', function (): void {
    $task = taskUpdTask(taskUpdProject());

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['status' => 'done'])
        ->call('save')
        ->assertHasNoFormErrors();

    $task->refresh();

    expect($task->status)->toBe(ProjectStatus::Done)
        ->and($task->completed_at)->not->toBeNull();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['status' => 'to_clarify'])
        ->call('save')
        ->assertHasNoFormErrors();

    $task->refresh();

    expect($task->status)->toBe(ProjectStatus::ToClarify)
        ->and($task->completed_at)->toBeNull()
        ->and($task->position)->toBe(0);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('switches freely between all six statuses with no workflow', function (): void {
    $task = taskUpdTask(taskUpdProject());
    $action = app(UpdateTask::class);

    foreach (['done', 'planned', 'ready_to_release', 'to_clarify', 'in_review', 'in_progress', 'done', 'in_review'] as $status) {
        $task = $action->handle($this->admin, $task, ['status' => $status]);

        expect($task->status->value)->toBe($status)
            ->and($task->completed_at !== null)->toBe($status === 'done');
    }

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('leaves the position alone when the status does not change', function (): void {
    $project = taskUpdProject();
    taskUpdTask($project);
    $task = taskUpdTask($project);

    $updated = app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example only a title', 'status' => 'planned', 'priority' => 'high']);

    expect($updated->position)->toBe(1)
        ->and($updated->title)->toBe('Example only a title')
        ->and($updated->priority->value)->toBe('high');
});

it('leaves a field alone when the data does not name it', function (): void {
    $task = taskUpdTask(taskUpdProject(), ['description' => 'Example kept text.', 'due_date' => '2031-05-20']);

    $updated = app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example partial update']);

    expect($updated->title)->toBe('Example partial update')
        ->and($updated->description)->toBe('Example kept text.')
        ->and($updated->due_date?->toDateString())->toBe('2031-05-20');
});

it('refuses an empty title and an unknown status as field errors and writes nothing', function (): void {
    $task = taskUpdTask(taskUpdProject());
    $action = app(UpdateTask::class);

    expect(fn () => $action->handle($this->admin, $task, ['title' => '  ']))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($this->admin, $task, ['status' => 'archived']))->toThrow(ValidationException::class);

    expect($task->refresh()->title)->toBe('Example update task')
        ->and($task->status)->toBe(ProjectStatus::Planned);
});

it('shows a status change in the task history and never the description', function (): void {
    $task = taskUpdTask(taskUpdProject());

    app(UpdateTask::class)->handle($this->admin, $task, ['status' => 'in_progress', 'description' => 'Example changed text.']);

    $activities = $task->activitiesAsSubject()->get();
    $logged = $activities->flatMap(static fn ($activity): array => array_keys((array) $activity->attribute_changes?->get('attributes', [])))->all();

    expect($activities)->not->toBeEmpty()
        ->and($logged)->toContain('status')
        ->and($logged)->not->toContain('description')
        ->and($logged)->not->toContain('position')
        ->and($logged)->not->toContain('completed_at')
        ->and(TaskHistoryRelationManager::canViewForRecord($task, EditTask::class))->toBeTrue();

    Livewire::test(TaskHistoryRelationManager::class, ['ownerRecord' => $task, 'pageClass' => EditTask::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($activities);
});

it('writes one history row for a change of status and title together', function (): void {
    $task = taskUpdTask(taskUpdProject());
    $before = $task->activitiesAsSubject()->count();

    app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example both changed', 'status' => 'in_review']);

    expect($task->activitiesAsSubject()->count())->toBe($before + 1);
});

it('refuses a Partner the update of a task of the own client', function (): void {
    $project = taskUpdProject(visible: true);
    $task = taskUpdTask($project);
    $partner = Canary::partnerFor($project->client_id);

    expect(fn () => app(UpdateTask::class)->handle($partner, $task, ['title' => 'Example forged title']))
        ->toThrow(AuthorizationException::class);

    expect($task->refresh()->title)->toBe('Example update task');
});

it('refuses a Partner the edit page and the history', function (): void {
    $project = taskUpdProject(visible: true);
    $task = taskUpdTask($project);

    $this->actingAs(Canary::partnerFor($project->client_id));

    $this->get('/admin/tasks/'.$task->reference.'/edit')->assertForbidden();
    expect(TaskHistoryRelationManager::canViewForRecord($task, EditTask::class))->toBeFalse();
});
