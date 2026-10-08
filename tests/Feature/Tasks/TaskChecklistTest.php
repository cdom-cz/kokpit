<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskChecklistItem;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The private todo checklist of a task or a subtask (TA-03): the Admin adds,
 * ticks, reorders and removes items on the edit page; a Partner sees none of it.
 * Every name and key is fictional.
 */

/**
 * A project with the given key, written through the domain Action.
 */
function taskChecklistProject(string $key = 'CHK', bool $visible = false): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example checklist project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => $visible,
    ]);
}

/**
 * A task of the project, optionally a subtask of the parent, created as the signed-in Admin.
 */
function taskChecklistTask(Project $project, ?Task $parent = null): Task
{
    return app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example checklist task'], $parent);
}

/**
 * The texts of the checklist of a task, in order, with their done flag.
 *
 * @return list<array{string, bool}>
 */
function taskChecklistRows(Task $task): array
{
    return TaskChecklistItem::query()
        ->where('task_id', $task->getKey())
        ->orderBy('position')
        ->orderBy('id')
        ->get()
        ->map(static fn (TaskChecklistItem $item): array => [$item->text, $item->is_done])
        ->all();
}

/**
 * The state of the checklist repeater of the page, keyed by item.
 *
 * @return array<string, array<string, mixed>>
 */
function taskChecklistState(Testable $page): array
{
    /** @var array<string, array<string, mixed>> $state */
    $state = $page->get('data.checklistItems');

    return $state;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('adds, ticks and reorders checklist items on the edit page and keeps them after a reload', function (): void {
    $task = taskChecklistTask(taskChecklistProject());

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->set('data.checklistItems', [
            'new-a' => ['text' => 'Example first item', 'is_done' => false],
            'new-b' => ['text' => 'Example second item', 'is_done' => false],
            'new-c' => ['text' => 'Example third item', 'is_done' => false],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(taskChecklistRows($task))->toBe([
        ['Example first item', false],
        ['Example second item', false],
        ['Example third item', false],
    ]);

    // Tick the second item and move the third one to the top.
    $page = Livewire::test(EditTask::class, ['record' => $task->reference]);
    $state = taskChecklistState($page);
    [$first, $second, $third] = array_values($state);

    $second['is_done'] = true;
    $keys = array_keys($state);

    $page->set('data.checklistItems', [
        $keys[2] => $third,
        $keys[0] => $first,
        $keys[1] => $second,
    ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(taskChecklistRows($task))->toBe([
        ['Example third item', false],
        ['Example first item', false],
        ['Example second item', true],
    ]);

    // A fresh page shows the items as they were left.
    $fresh = Livewire::test(EditTask::class, ['record' => $task->reference]);

    expect(array_map(static fn (array $row): string => $row['text'], array_values(taskChecklistState($fresh))))
        ->toBe(['Example third item', 'Example first item', 'Example second item']);
});

it('removes a checklist item on save', function (): void {
    $task = taskChecklistTask(taskChecklistProject());
    $task->checklistItems()->createMany([
        ['text' => 'Example kept item', 'position' => 1],
        ['text' => 'Example removed item', 'position' => 2],
    ]);

    $page = Livewire::test(EditTask::class, ['record' => $task->reference]);
    $state = taskChecklistState($page);
    $keys = array_keys($state);

    $page->set('data.checklistItems', [$keys[0] => $state[$keys[0]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(taskChecklistRows($task))->toBe([['Example kept item', false]]);
});

it('keeps the checklist of a subtask on its own edit page', function (): void {
    $project = taskChecklistProject();
    $parent = taskChecklistTask($project);
    $subtask = taskChecklistTask($project, $parent);

    Livewire::test(EditTask::class, ['record' => $subtask->reference])
        ->set('data.checklistItems', [
            'new-a' => ['text' => 'Example subtask item', 'is_done' => true],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(taskChecklistRows($subtask))->toBe([['Example subtask item', true]])
        ->and(taskChecklistRows($parent))->toBe([]);
});

it('shows a Partner no checklist item, not even on a task of the own visible project', function (): void {
    $project = taskChecklistProject('SEE', true);
    $task = taskChecklistTask($project);
    $task->checklistItems()->create(['text' => Canary::canary('checklist'), 'position' => 1]);

    $partner = Canary::partnerFor($project->client_id);
    $this->actingAs($partner);

    expect(Task::query()->whereKey($task->getKey())->exists())->toBeTrue()
        ->and(TaskChecklistItem::query()->count())->toBe(0)
        ->and(Task::query()->findOrFail($task->getKey())->checklistItems)->toHaveCount(0);
});
