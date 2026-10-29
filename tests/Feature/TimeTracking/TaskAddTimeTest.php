<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Billing\BillableDefault;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\EditTimeEntry;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * "Přidat čas" on the task view (D-06): the standard time-entry fields with the client, project and task
 * taken from the task and locked, written by the existing CreateTimeEntry Action with all its rules.
 * Every name is fictional.
 */

/**
 * A task of a fresh client and project, written through the domain Actions.
 *
 * @param  array<string, mixed>  $projectData
 */
function taskAddTimeTask(array $projectData = []): Task
{
    $project = app(CreateProject::class)->handle(Client::factory()->create(['name' => 'Cihla']), [
        'name' => 'Example add time project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
        ...$projectData,
    ]);

    return app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example add time task']);
}

/**
 * The view page of the task as the Admin sees it.
 */
function taskAddTimePage(Task $task): Testable
{
    return Livewire::test(ViewTask::class, ['record' => $task->reference]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

it('records a one-hour entry for the task with one click and updates the worked time of the page', function (): void {
    $task = taskAddTimeTask();
    $project = Project::query()->findOrFail($task->project_id);

    $page = taskAddTimePage($task)
        ->assertActionVisible('addTime')
        ->mountAction('addTime')
        ->assertSchemaStateSet([
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'billable' => app(BillableDefault::class)->for($task),
            'started_at' => '2026-10-14 09:00:00',
            'ended_at' => '2026-10-14 10:00:00',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Záznam byl uložen')
        ->assertDispatched('time-entry-saved');

    $entry = TimeEntry::query()->firstOrFail();

    expect(TimeEntry::query()->count())->toBe(1)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->client_id)->toBe($project->client_id)
        ->and($entry->project_id)->toBe($project->id)
        ->and($entry->task_id)->toBe($task->id)
        ->and($entry->duration_seconds)->toBe(3600);

    $page->assertSee('Odpracováno')->assertSee('1:00');
});

it('shows the client, project and task disabled in the modal', function (): void {
    $task = taskAddTimeTask();

    $page = taskAddTimePage($task)->mountAction('addTime');

    foreach (['client_id', 'project_id', 'task_id'] as $field) {
        $page->assertSchemaComponentExists(
            $field,
            'mountedActionSchema0',
            static fn (Select $component): bool => $component->isDisabled(),
        );
    }

    $page->assertSchemaComponentExists(
        'started_at',
        'mountedActionSchema0',
        static fn (DateTimePicker $component): bool => ! $component->isDisabled(),
    );

    expect(__('kokpit.time.task.context_locked'))->toBe('Klient, projekt a úkol jsou dané úkolem.');
});

it('never lets a client, project or task sent with the modal decide the entry', function (): void {
    $task = taskAddTimeTask();
    $other = taskAddTimeTask();

    taskAddTimePage($task)
        ->callAction('addTime', [
            'client_id' => Project::query()->findOrFail($other->project_id)->client_id,
            'project_id' => $other->project_id,
            'task_id' => $other->id,
        ])
        ->assertHasNoActionErrors();

    $entry = TimeEntry::query()->firstOrFail();

    expect(TimeEntry::query()->count())->toBe(1)
        ->and($entry->task_id)->toBe($task->id)
        ->and($entry->project_id)->toBe($task->project_id)
        ->and($entry->client_id)->toBe(Project::query()->findOrFail($task->project_id)->client_id);
});

it('keeps the modal open and creates nothing when the project is archived', function (): void {
    $task = taskAddTimeTask();
    Project::query()->findOrFail($task->project_id)->delete();

    taskAddTimePage($task)
        ->mountAction('addTime')
        ->callMountedAction()
        ->assertHasActionErrors(['project_id'])
        ->assertActionMounted('addTime')
        ->assertNotDispatched('time-entry-saved');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('keeps the modal open and creates nothing when the client is archived', function (): void {
    $task = taskAddTimeTask();
    Client::query()->findOrFail(Project::query()->findOrFail($task->project_id)->client_id)->delete();

    taskAddTimePage($task)
        ->mountAction('addTime')
        ->callMountedAction()
        ->assertHasActionErrors()
        ->assertActionMounted('addTime');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('refuses an end before the start under Konec and creates nothing', function (): void {
    $task = taskAddTimeTask();

    taskAddTimePage($task)
        ->callAction('addTime', ['started_at' => '2026-10-14 10:00:00', 'ended_at' => '2026-10-14 09:00:00'])
        ->assertHasActionErrors(['ended_at']);

    expect(TimeEntry::query()->count())->toBe(0);
});

it('hides the action for an archived task', function (): void {
    $task = taskAddTimeTask();

    taskAddTimePage($task)->assertActionVisible('addTime');

    $task->delete();

    taskAddTimePage($task)->assertActionHidden('addTime');
});

it('keeps the client, project and task editable on the create and edit pages of a time entry', function (): void {
    Livewire::test(CreateTimeEntry::class)
        ->assertFormFieldIsEnabled('client_id')
        ->assertFormFieldIsEnabled('project_id')
        ->assertFormFieldIsEnabled('task_id');

    $entry = TimeEntry::factory()->create();

    Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->assertFormFieldIsEnabled('client_id')
        ->assertFormFieldIsEnabled('task_id');
});

it('saves an entry that overlaps another one, the overlap only warns', function (): void {
    $task = taskAddTimeTask();
    TimeEntry::factory()->create([
        'user_id' => $this->admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-14 09:30:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-14 10:30:00', 'UTC'),
    ]);

    taskAddTimePage($task)
        ->callAction('addTime')
        ->assertHasNoActionErrors();

    expect(TimeEntry::query()->where('task_id', $task->id)->count())->toBe(1);
});

it('pre-fills a billable default of false from a non-billable task and saves it so', function (): void {
    $task = taskAddTimeTask();
    $task = app(UpdateTask::class)->handle($this->admin, $task, ['billing_type' => 'non_billable'])->refresh();

    taskAddTimePage($task)
        ->mountAction('addTime')
        ->assertSchemaStateSet(['billable' => false])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(TimeEntry::query()->where('task_id', $task->id)->firstOrFail()->billable)->toBeFalse();
});
