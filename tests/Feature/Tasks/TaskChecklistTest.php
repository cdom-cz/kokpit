<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskChecklistItem;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

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

/**
 * Gives the task a checklist of the given number of items, the first ones done.
 */
function taskChecklistFill(Task $task, int $total, int $done): void
{
    for ($i = 1; $i <= $total; $i++) {
        $task->checklistItems()->create(['text' => 'Example item '.$i, 'is_done' => $i <= $done, 'position' => $i]);
    }
}

/**
 * The number of queries the callback runs.
 */
function taskChecklistQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('shows the checklist progress as done/total in the list and on the task page', function (): void {
    $project = taskChecklistProject();
    $with = taskChecklistTask($project);
    $without = taskChecklistTask($project);
    taskChecklistFill($with, 3, 1);

    Livewire::test(ListTasks::class)
        ->assertTableColumnStateSet('checklist_progress', '1/3', $with)
        ->assertTableColumnStateSet('checklist_progress', null, $without);

    Livewire::test(ViewTask::class, ['record' => $with->reference])
        ->assertSeeText('1/3');

    Livewire::test(ViewTask::class, ['record' => $without->reference])
        ->assertDontSeeText('0/0')
        ->assertDontSeeText(__('kokpit.tasks.checklist.progress'));
});

it('counts the checklist of all tasks of a page with a constant number of queries', function (): void {
    $project = taskChecklistProject();
    $tasks = [taskChecklistTask($project), taskChecklistTask($project)];
    taskChecklistFill($tasks[0], 3, 1);
    taskChecklistFill($tasks[1], 3, 2);

    $render = static fn (): mixed => Livewire::test(ListTasks::class)->set('tableRecordsPerPage', 25);

    $render();
    $few = taskChecklistQueryCount($render);

    for ($i = 0; $i < 18; $i++) {
        $task = taskChecklistTask($project);
        taskChecklistFill($task, 3, 1);
    }

    $many = taskChecklistQueryCount($render);

    expect(Task::query()->count())->toBe(20)
        ->and($many)->toBe($few);

    Livewire::test(ListTasks::class)
        ->set('tableRecordsPerPage', 25)
        ->assertTableColumnStateSet('checklist_progress', '1/3', $tasks[0])
        ->assertTableColumnStateSet('checklist_progress', '2/3', $tasks[1]);
});

it('refuses a malformed checklist item in the database', function (): void {
    $task = taskChecklistTask(taskChecklistProject());

    $insert = static fn (array $row): Closure => static fn (): bool => DB::table('task_checklist_items')->insert([
        'id' => (string) Str::uuid7(),
        'task_id' => $task->getKey(),
        'text' => 'Example item',
        'is_done' => false,
        'position' => 1,
        ...$row,
    ]);

    // The well-formed row is accepted, so each refusal below is caused by its own field.
    RawSql::expectAllowed($insert([]));

    RawSql::expectSqlState('23514', $insert(['text' => '']));
    RawSql::expectSqlState('23514', $insert(['text' => " \t\n "]));
    RawSql::expectSqlState('22001', $insert(['text' => str_repeat('a', 501)]));
    RawSql::expectAllowed($insert(['text' => str_repeat('a', 500)]));
    RawSql::expectSqlState('23514', $insert(['position' => -1]));
    RawSql::expectSqlState('23503', $insert(['task_id' => (string) Str::uuid7()]));
});
