<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The start and stop affordances on the task page, the task list and the board cards (TI-01, D-01,
 * D-02, D-03), the "Čas" section of the task page and the absence of all of it for a Partner.
 * Every name is fictional.
 */

/**
 * A task of a fresh hourly project, written through the domain Actions.
 *
 * @param  array<string, mixed>  $changes  billing keys applied through UpdateTask like on the edit page
 */
function taskStartTask(array $changes = [], ?Project $project = null): Task
{
    $project ??= app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example start project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
    ]);

    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example start task']);

    return $changes === [] ? $task : app(UpdateTask::class)->handle(test()->admin, $task, $changes)->refresh();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('starts a timer for the task from its page in one click with the context of the task', function (): void {
    $task = taskStartTask();

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionVisible('toggleTimer')
        ->assertActionHasLabel('toggleTimer', 'Spustit časovač')
        ->assertActionHasColor('toggleTimer', 'gray')
        ->callAction('toggleTimer')
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn');

    $entry = TimeEntry::query()->whereNull('ended_at')->sole();

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->project_id)->toBe($task->project_id)
        ->and($entry->client_id)->toBe(Project::query()->findOrFail($task->project_id)->client_id)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->billable)->toBeTrue()
        ->and($entry->description)->toBeNull();
});

it('stores a non-billable entry when the task is non-billable', function (): void {
    $task = taskStartTask(['billing_type' => 'non_billable']);

    Livewire::test(ViewTask::class, ['record' => $task->reference])->callAction('toggleTimer');

    expect(TimeEntry::query()->whereNull('ended_at')->sole()->billable)->toBeFalse();
});

it('stops the running timer of another task, keeps it and names its duration in the toast', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $other = taskStartTask();
    $old = TimeEntry::factory()->forTask($other)->create([
        'user_id' => $this->admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 06:30:00', 'UTC'),
        'ended_at' => null,
    ]);
    $task = taskStartTask();

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->callAction('toggleTimer')
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn');

    $this->travelBack();

    expect($old->refresh()->ended_at)->not->toBeNull()
        ->and($old->duration_seconds)->toBe(5400)
        ->and(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($task->id);
});

it('says in the toast which previous record was stopped', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    TimeEntry::factory()->forTask(taskStartTask())->create([
        'user_id' => $this->admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 06:30:00', 'UTC'),
        'ended_at' => null,
    ]);
    $task = taskStartTask();

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->callAction('toggleTimer')
        ->assertNotified(
            Notification::make()
                ->success()
                ->title('Časovač byl spuštěn')
                ->body('Předchozí záznam (1:30) byl zastaven a uložen.'),
        );
});

it('offers the stop in the same slot while the task is the running one and stops it', function (): void {
    $task = taskStartTask();
    TimeEntry::factory()->forTask($task)->running()->create(['user_id' => $this->admin->id]);

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionHasLabel('toggleTimer', 'Zastavit časovač')
        ->assertActionHasColor('toggleTimer', 'warning')
        ->assertActionHasIcon('toggleTimer', Heroicon::OutlinedStop)
        ->callAction('toggleTimer')
        ->assertDispatched('timer-stopped')
        ->assertNotified('Časovač byl zastaven');

    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(0);
});

it('offers the same one-click toggle on the edit page', function (): void {
    $task = taskStartTask();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->assertActionHasLabel('toggleTimer', 'Spustit časovač')
        ->callAction('toggleTimer')
        ->assertNotified('Časovač byl spuštěn')
        ->assertActionHasLabel('toggleTimer', 'Zastavit časovač');

    expect(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($task->id);
});

it('offers no timer action on the page of an archived task', function (): void {
    $task = taskStartTask();
    app(ArchiveTask::class)->handle($this->admin, $task);

    Livewire::test(ViewTask::class, ['record' => $task->reference])->assertActionHidden('toggleTimer');

    expect(TimeEntry::query()->count())->toBe(0);
});
