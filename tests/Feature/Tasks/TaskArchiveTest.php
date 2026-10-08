<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Archive and restore of tasks (TA-01, TA-02, research A9, Pitfall 2): a task is
 * archived, never deleted; a parent keeps its live subtasks; a restore re-appends
 * the card under the board lock. Every name and key is fictional.
 */

/**
 * A project with the given key, written through the domain Action.
 */
function taskArchProject(string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example archive project',
        'key' => $key,
        'billing_type' => 'hourly',
    ]);
}

/**
 * A task of the project, optionally a subtask of the parent.
 *
 * @param  array<string, mixed>  $data
 */
function taskArchTask(Project $project, string $title, ?Task $parent = null, array $data = []): Task
{
    return app(CreateTask::class)->handle(test()->admin, $project, ['title' => $title, ...$data], $parent);
}

/**
 * The field errors of a callable that must throw a ValidationException.
 *
 * @return array<string, list<string>>
 */
function taskArchErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('archives a task without subtasks, hides it from the default list and shows it with the trashed filter', function (): void {
    $project = taskArchProject();
    $kept = taskArchTask($project, 'Example kept task');
    $task = taskArchTask($project, 'Example archived task');

    app(ArchiveTask::class)->handle($this->admin, $task);

    expect($task->refresh()->trashed())->toBeTrue();

    Livewire::test(ListTasks::class)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$task])
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$task])
        ->assertCanNotSeeTableRecords([$kept]);
});

it('leaves an archived task out of the global search', function (): void {
    $project = taskArchProject();
    $kept = taskArchTask($project, 'Example searchable task');
    $archived = taskArchTask($project, 'Example hidden task');

    app(ArchiveTask::class)->handle($this->admin, $archived);

    expect(TaskResource::getGlobalSearchEloquentQuery()->pluck('reference')->all())->toBe([$kept->reference]);
});

it('keeps the tags of an archived task and brings them back on restore', function (): void {
    $task = taskArchTask(taskArchProject(), 'Example tagged task', data: ['tags' => ['example-tag']]);

    app(ArchiveTask::class)->handle($this->admin, $task);

    expect(Task::query()->withTrashed()->findOrFail($task->id)->tags()->pluck('name')->all())->toBe(['example-tag']);

    app(RestoreTask::class)->handle($this->admin, $task);

    expect(Task::query()->findOrFail($task->id)->tags()->pluck('name')->all())->toBe(['example-tag']);
});

it('refuses to archive a parent with an active subtask and changes nothing', function (): void {
    $project = taskArchProject();
    $parent = taskArchTask($project, 'Example parent task');
    $subtask = taskArchTask($project, 'Example active subtask', $parent);

    $errors = taskArchErrors(fn () => app(ArchiveTask::class)->handle($this->admin, $parent));

    expect($errors['task'] ?? [])->toBe([__('kokpit.tasks.errors.has_active_subtasks')])
        ->and($parent->refresh()->trashed())->toBeFalse()
        ->and($subtask->refresh()->trashed())->toBeFalse();

    app(ArchiveTask::class)->handle($this->admin, $subtask);
    app(ArchiveTask::class)->handle($this->admin, $parent);

    expect($parent->refresh()->trashed())->toBeTrue();
});

it('always allows archiving a subtask', function (): void {
    $project = taskArchProject();
    $parent = taskArchTask($project, 'Example parent task');
    $subtask = taskArchTask($project, 'Example archived subtask', $parent);

    app(ArchiveTask::class)->handle($this->admin, $subtask);

    expect($subtask->refresh()->trashed())->toBeTrue()
        ->and($parent->refresh()->trashed())->toBeFalse();
});

it('puts a restored task at the end of its column when its old position was taken', function (): void {
    $project = taskArchProject();
    taskArchTask($project, 'Example first task');
    $second = taskArchTask($project, 'Example second task');

    expect($second->position)->toBe(1);

    app(ArchiveTask::class)->handle($this->admin, $second);
    $taker = taskArchTask($project, 'Example taker task');

    expect($taker->position)->toBe(1);

    $restored = app(RestoreTask::class)->handle($this->admin, $second);

    expect($restored->trashed())->toBeFalse()
        ->and($restored->position)->toBe(2)
        ->and($taker->refresh()->position)->toBe(1);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('restores a task into the column of its stored status', function (): void {
    $project = taskArchProject();
    $task = taskArchTask($project, 'Example moving task');
    app(UpdateTask::class)->handle($this->admin, $task, ['status' => 'in_progress']);
    $resident = taskArchTask($project, 'Example resident task', data: ['status' => 'in_progress']);

    app(ArchiveTask::class)->handle($this->admin, $task);
    $restored = app(RestoreTask::class)->handle($this->admin, $task);

    expect($restored->status->value)->toBe('in_progress')
        ->and($restored->position)->toBe($resident->position + 1);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('keeps the completion time of a restored Done task', function (): void {
    $task = taskArchTask(taskArchProject(), 'Example finished task', data: ['status' => 'done']);
    Task::query()->whereKey($task->id)->update(['completed_at' => '2026-01-05 10:00:00']);

    app(ArchiveTask::class)->handle($this->admin, $task);
    $restored = app(RestoreTask::class)->handle($this->admin, $task);

    expect($restored->status->value)->toBe('done')
        ->and($restored->completed_at?->format('Y-m-d H:i:s'))->toBe('2026-01-05 10:00:00')
        ->and($restored->position)->toBe(0);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('refuses to restore a subtask whose parent is archived', function (): void {
    $project = taskArchProject();
    $parent = taskArchTask($project, 'Example parent task');
    $subtask = taskArchTask($project, 'Example hanging subtask', $parent);

    app(ArchiveTask::class)->handle($this->admin, $subtask);
    app(ArchiveTask::class)->handle($this->admin, $parent);

    $errors = taskArchErrors(fn () => app(RestoreTask::class)->handle($this->admin, $subtask));

    expect($errors['task'] ?? [])->toBe([__('kokpit.tasks.errors.parent_archived')])
        ->and($subtask->refresh()->trashed())->toBeTrue();

    app(RestoreTask::class)->handle($this->admin, $parent);
    app(RestoreTask::class)->handle($this->admin, $subtask);

    expect($subtask->refresh()->trashed())->toBeFalse();
});

it('treats archiving an archived task and restoring an active task as no-ops', function (): void {
    $task = taskArchTask(taskArchProject(), 'Example repeated task');

    app(ArchiveTask::class)->handle($this->admin, $task);
    $deletedAt = $task->refresh()->deleted_at;

    app(ArchiveTask::class)->handle($this->admin, $task);

    expect($task->refresh()->deleted_at?->toIso8601String())->toBe($deletedAt?->toIso8601String());

    app(RestoreTask::class)->handle($this->admin, $task);
    $position = $task->refresh()->position;

    app(RestoreTask::class)->handle($this->admin, $task);

    expect($task->refresh()->trashed())->toBeFalse()
        ->and($task->position)->toBe($position);
});

it('never hands the number of an archived task out again', function (): void {
    $project = taskArchProject();
    $first = taskArchTask($project, 'Example first task');

    app(ArchiveTask::class)->handle($this->admin, $first);
    $next = taskArchTask($project, 'Example next task');

    expect($first->reference)->toBe('ABC-1')
        ->and($next->reference)->toBe('ABC-2');
});

it('refuses a Partner that calls the archive or the restore Action', function (): void {
    $client = Client::factory()->create();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Example partner project',
        'key' => 'PRT',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
    $task = taskArchTask($project, 'Example guarded task');
    $archived = taskArchTask($project, 'Example guarded archived task');
    app(ArchiveTask::class)->handle($this->admin, $archived);

    $partner = Canary::partnerFor($client->id);
    $this->actingAs($partner);

    expect(fn () => app(ArchiveTask::class)->handle($partner, $task))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestoreTask::class)->handle($partner, $archived))->toThrow(AuthorizationException::class);

    $this->actingAs($this->admin);

    expect($task->refresh()->trashed())->toBeFalse()
        ->and(Task::query()->withTrashed()->findOrFail($archived->id)->trashed())->toBeTrue();
});

it('archives and restores from the row actions of the task list', function (): void {
    $task = taskArchTask(taskArchProject(), 'Example row task');

    Livewire::test(ListTasks::class)
        ->callAction(TestAction::make('archive')->table($task))
        ->assertHasNoActionErrors();

    expect($task->refresh()->trashed())->toBeTrue();

    Livewire::test(ListTasks::class)
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('restore')->table($task))
        ->assertHasNoActionErrors();

    expect($task->refresh()->trashed())->toBeFalse();
});

it('archives and restores from the header actions of the task page and opens an archived task by its address', function (): void {
    $task = taskArchTask(taskArchProject(), 'Example page task');

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionVisible('archive')
        ->assertActionHidden('restore')
        ->callAction('archive');

    expect($task->refresh()->trashed())->toBeTrue();

    $this->get('/admin/tasks/ABC-1')->assertOk()->assertSee('Example page task');

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionHidden('archive')
        ->assertActionVisible('restore')
        ->callAction('restore');

    expect($task->refresh()->trashed())->toBeFalse();
});

it('tells the Admin why a parent with active subtasks is not archived', function (): void {
    $project = taskArchProject();
    $parent = taskArchTask($project, 'Example busy parent');
    taskArchTask($project, 'Example busy subtask', $parent);

    Livewire::test(ViewTask::class, ['record' => $parent->reference])
        ->callAction('archive')
        ->assertNotified(__('kokpit.tasks.errors.has_active_subtasks'));

    expect($parent->refresh()->trashed())->toBeFalse();
});

it('offers no edit page for an archived task', function (): void {
    $task = taskArchTask(taskArchProject(), 'Example frozen task');
    app(ArchiveTask::class)->handle($this->admin, $task);

    $this->get('/admin/tasks/ABC-1/edit')->assertForbidden();

    Livewire::test(ViewTask::class, ['record' => $task->reference])->assertActionHidden('edit');
});

it('offers no force delete anywhere on the task resource', function (): void {
    $task = taskArchTask(taskArchProject(), 'Example permanent task');

    Livewire::test(ListTasks::class)
        ->assertTableActionDoesNotExist('forceDelete')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('delete');

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('delete');

    $sources = collect([
        (new ReflectionClass(TaskResource::class))->getFileName(),
        ...glob(dirname((string) (new ReflectionClass(TaskResource::class))->getFileName()).'/TaskResource/*/*.php') ?: [],
    ])->map(static fn (string|false $file): string => (string) file_get_contents((string) $file))->implode("\n");

    expect($sources)->not->toContain('ForceDelete')
        ->and($sources)->not->toContain('forceDelete')
        ->and($sources)->not->toContain('DeleteAction');
});
