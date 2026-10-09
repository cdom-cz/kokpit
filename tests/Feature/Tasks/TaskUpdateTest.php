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
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/**
 * The field errors an Action call raises, keyed by the data key.
 *
 * @param  Closure(): mixed  $callback
 * @return array<string, list<string>>
 */
function taskUpdErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('offers exactly the active Admin and the active Partners of the project client as people', function (): void {
    $project = taskUpdProject(visible: true);
    $task = taskUpdTask($project);
    $partner = Canary::partnerFor($project->client_id);
    $deactivated = Canary::partnerFor($project->client_id);
    $deactivated->forceFill(['deactivated_at' => now()])->save();
    $other = Canary::partnerFor(Client::factory()->create()->id);

    $expected = [$this->admin->id, $partner->id];

    foreach (['assignee_id', 'requester_id'] as $field) {
        Livewire::test(EditTask::class, ['record' => $task->reference])
            ->assertFormFieldExists($field, static function (Select $select) use ($expected, $deactivated, $other): bool {
                $ids = array_keys($select->getOptions());
                sort($ids);
                $wanted = $expected;
                sort($wanted);

                return $ids === $wanted && ! in_array($deactivated->id, $ids, true) && ! in_array($other->id, $ids, true);
            });
    }
});

it('saves a rename of a task whose assignee was deactivated and keeps the assignee', function (): void {
    $project = taskUpdProject(visible: true);
    $task = taskUpdTask($project);
    $partner = Canary::partnerFor($project->client_id);
    app(UpdateTask::class)->handle($this->admin, $task, ['assignee_id' => $partner->id]);
    $partner->forceFill(['deactivated_at' => now()])->save();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['title' => 'Example renamed after offboarding'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormFieldExists('assignee_id', static fn (Select $select): bool => array_key_exists($partner->id, $select->getOptions()));

    expect($task->refresh()->title)->toBe('Example renamed after offboarding')
        ->and($task->assignee_id)->toBe($partner->id);
});

it('saves the people chosen on the edit page', function (): void {
    $project = taskUpdProject(visible: true);
    $task = taskUpdTask($project);
    $partner = Canary::partnerFor($project->client_id);

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['assignee_id' => $this->admin->id, 'requester_id' => $partner->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($task->refresh()->requester_id)->toBe($partner->id)
        ->and($task->assignee_id)->toBe($this->admin->id);
});

it('refuses a forged assignee of another client as a field error and changes nothing', function (): void {
    $task = taskUpdTask(taskUpdProject(visible: true));
    $forged = Canary::partnerFor(Client::factory()->create()->id);

    $errors = taskUpdErrors(fn () => app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example forged change', 'assignee_id' => $forged->id]));

    expect($errors)->toHaveKey('assignee_id')
        ->and($task->refresh()->assignee_id)->toBe($this->admin->id)
        ->and($task->title)->toBe('Example update task');

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['assignee_id' => $forged->id])
        ->call('save')
        ->assertHasFormErrors(['assignee_id']);

    expect($task->refresh()->assignee_id)->toBe($this->admin->id);
});

it('refuses a forged requester and a deactivated account as field errors', function (): void {
    $project = taskUpdProject(visible: true);
    $task = taskUpdTask($project);
    $deactivated = Canary::partnerFor($project->client_id);
    $deactivated->forceFill(['deactivated_at' => now()])->save();
    $forged = Canary::partnerFor(Client::factory()->create()->id);
    $action = app(UpdateTask::class);

    expect(taskUpdErrors(fn () => $action->handle($this->admin, $task, ['requester_id' => $forged->id])))->toHaveKey('requester_id')
        ->and(taskUpdErrors(fn () => $action->handle($this->admin, $task, ['assignee_id' => $deactivated->id])))->toHaveKey('assignee_id')
        ->and(taskUpdErrors(fn () => $action->handle($this->admin, $task, ['assignee_id' => (string) Str::uuid()])))->toHaveKey('assignee_id');
});

it('stores tags entered on the edit page as task tags and shows them on the task page', function (): void {
    $task = taskUpdTask(taskUpdProject());

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['tags' => ['Example urgent', 'Example client call']])
        ->call('save')
        ->assertHasNoFormErrors();

    $names = $task->refresh()->tagsWithType('task')->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Example client call', 'Example urgent'])
        ->and($task->tags()->where('type', '<>', 'task')->count())->toBe(0);

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertSee('Example urgent');

    app(UpdateTask::class)->handle($this->admin, $task, ['tags' => ['Example urgent']]);

    expect($task->refresh()->tagsWithType('task')->pluck('name')->all())->toBe(['Example urgent']);
});

it('stores the tags given to CreateTask as task tags', function (): void {
    $task = app(CreateTask::class)->handle($this->admin, taskUpdProject(), ['title' => 'Example tagged task', 'tags' => ['Example first', 'Example second']]);

    expect($task->tagsWithType('task')->pluck('name')->sort()->values()->all())->toBe(['Example first', 'Example second'])
        ->and($task->tags()->where('type', '<>', 'task')->count())->toBe(0);
});

it('refuses a due date before the start date as a field error on due_date before any write', function (): void {
    $task = taskUpdTask(taskUpdProject());
    $action = app(UpdateTask::class);

    $errors = taskUpdErrors(fn () => $action->handle($this->admin, $task, ['title' => 'Example dated change', 'start_date' => '2031-05-20', 'due_date' => '2031-05-19']));

    expect($errors)->toHaveKey('due_date')
        ->and($errors['due_date'][0])->toBe(__('kokpit.tasks.errors.dates_order'))
        ->and($task->refresh()->title)->toBe('Example update task')
        ->and($task->start_date)->toBeNull();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->fillForm(['start_date' => '2031-05-20', 'due_date' => '2031-05-19'])
        ->call('save')
        ->assertHasFormErrors(['due_date']);

    expect($task->refresh()->start_date)->toBeNull();
});

it('compares a new due date with the stored start date', function (): void {
    $task = taskUpdTask(taskUpdProject(), ['start_date' => '2031-05-20']);

    expect(taskUpdErrors(fn () => app(UpdateTask::class)->handle($this->admin, $task, ['due_date' => '2031-05-01'])))->toHaveKey('due_date');

    $updated = app(UpdateTask::class)->handle($this->admin, $task, ['due_date' => '2031-05-20']);

    expect($updated->due_date?->toDateString())->toBe('2031-05-20');
});

it('refuses a due date before the start date in CreateTask as a field error', function (): void {
    $errors = taskUpdErrors(fn () => app(CreateTask::class)->handle($this->admin, taskUpdProject(), ['title' => 'Example dated task', 'start_date' => '2031-05-20', 'due_date' => '2031-05-19']));

    expect($errors)->toHaveKey('due_date')
        ->and(Task::query()->count())->toBe(0);
});

it('refuses a date that is not a calendar day as a field error', function (): void {
    $task = taskUpdTask(taskUpdProject());

    expect(taskUpdErrors(fn () => app(UpdateTask::class)->handle($this->admin, $task, ['start_date' => '2031-02-31'])))->toHaveKey('start_date')
        ->and(taskUpdErrors(fn () => app(UpdateTask::class)->handle($this->admin, $task, ['due_date' => 'tomorrow'])))->toHaveKey('due_date');
});

it('shows the shared description hint when the project is client-visible and hides it otherwise', function (): void {
    $shared = taskUpdTask(taskUpdProject('SHA', visible: true));
    $private = taskUpdTask(taskUpdProject('PRV'));
    $hint = __('kokpit.tasks.hints.description_shared');

    expect($hint)->not->toBe('kokpit.tasks.hints.description_shared');

    Livewire::test(EditTask::class, ['record' => $shared->reference])->assertSee($hint);
    Livewire::test(EditTask::class, ['record' => $private->reference])->assertDontSee($hint);
});
