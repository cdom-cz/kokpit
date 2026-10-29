<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Billing\BillableDefault;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
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
