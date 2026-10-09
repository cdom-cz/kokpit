<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The filters of the Admin task list (TA-05): client, project, status, priority,
 * assignee, task tag and an inclusive due date range, their combination, the
 * empty state and the stable default order. Every name and key is fictional.
 */

/**
 * A project of the given client with the given key, written through the domain Action.
 */
function taskFilterProject(Client $client, string $key): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example filter project '.$key,
        'key' => $key,
        'billing_type' => 'hourly',
    ]);
}

/**
 * A task of the project with the given attributes.
 *
 * @param  array<string, mixed>  $attributes
 */
function taskFilterTask(Project $project, array $attributes = []): Task
{
    return Task::factory()->create(['project_id' => $project->id, ...$attributes]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('narrows the list by client', function (): void {
    $projectA = taskFilterProject(Client::factory()->create(), 'AAA');
    $projectB = taskFilterProject($clientB = Client::factory()->create(), 'BBB');
    $a = taskFilterTask($projectA);
    $b = taskFilterTask($projectB);

    Livewire::test(ListTasks::class)
        ->filterTable('client', $clientB->id)
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a]);
});

it('narrows the list by project', function (): void {
    $client = Client::factory()->create();
    $projectA = taskFilterProject($client, 'AAA');
    $projectB = taskFilterProject($client, 'BBB');
    $a = taskFilterTask($projectA);
    $b = taskFilterTask($projectB);

    Livewire::test(ListTasks::class)
        ->filterTable('project', $projectA->id)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b]);
});

it('narrows the list by status and by priority', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $planned = taskFilterTask($project, ['status' => 'planned', 'priority' => 'normal']);
    $done = taskFilterTask($project, ['status' => 'done', 'priority' => 'high']);

    Livewire::test(ListTasks::class)
        ->filterTable('status', 'done')
        ->assertCanSeeTableRecords([$done])
        ->assertCanNotSeeTableRecords([$planned])
        ->removeTableFilter('status')
        ->filterTable('priority', 'high')
        ->assertCanSeeTableRecords([$done])
        ->assertCanNotSeeTableRecords([$planned]);
});

it('narrows the list by assignee', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $someone = User::factory()->create();
    $mine = taskFilterTask($project, ['assignee_id' => $someone->id]);
    $other = taskFilterTask($project);

    Livewire::test(ListTasks::class)
        ->filterTable('assignee', $someone->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('narrows the list by a task tag and offers task tags only', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $tagged = taskFilterTask($project);
    $plain = taskFilterTask($project);
    $tagged->attachTag('example-label', TagType::Task->value);
    $project->attachTag('example-project-label', TagType::Project->value);

    $taskTag = Tag::query()->where('type', TagType::Task->value)->firstOrFail();
    $projectTag = Tag::query()->where('type', TagType::Project->value)->firstOrFail();

    Livewire::test(ListTasks::class)
        ->assertTableFilterExists('tag')
        ->filterTable('tag', $taskTag->id)
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$plain]);

    $options = collect(Livewire::test(ListTasks::class)->instance()->getTable()->getFilter('tag')->getOptions());

    expect($options->keys()->all())->toBe([$taskTag->id])
        ->and($options->keys()->all())->not->toContain($projectTag->id);
});

it('combines two filters with AND', function (): void {
    $client = Client::factory()->create();
    $projectA = taskFilterProject($client, 'AAA');
    $projectB = taskFilterProject($client, 'BBB');
    $match = taskFilterTask($projectA, ['status' => 'done']);
    $wrongStatus = taskFilterTask($projectA, ['status' => 'planned']);
    $wrongProject = taskFilterTask($projectB, ['status' => 'done']);

    Livewire::test(ListTasks::class)
        ->filterTable('project', $projectA->id)
        ->filterTable('status', 'done')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$wrongStatus, $wrongProject]);
});

it('lists a task due exactly on the from day and on the to day and not one day outside', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $before = taskFilterTask($project, ['due_date' => '2030-03-09']);
    $first = taskFilterTask($project, ['due_date' => '2030-03-10']);
    $middle = taskFilterTask($project, ['due_date' => '2030-03-11']);
    $last = taskFilterTask($project, ['due_date' => '2030-03-12']);
    $after = taskFilterTask($project, ['due_date' => '2030-03-13']);

    Livewire::test(ListTasks::class)
        ->filterTable('due', ['due_from' => '2030-03-10', 'due_until' => '2030-03-12'])
        ->assertCanSeeTableRecords([$first, $middle, $last])
        ->assertCanNotSeeTableRecords([$before, $after]);
});

it('lists only the task due that day when the from day and the to day are the same', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $before = taskFilterTask($project, ['due_date' => '2030-03-09']);
    $day = taskFilterTask($project, ['due_date' => '2030-03-10']);
    $after = taskFilterTask($project, ['due_date' => '2030-03-11']);

    Livewire::test(ListTasks::class)
        ->filterTable('due', ['due_from' => '2030-03-10', 'due_until' => '2030-03-10'])
        ->assertCanSeeTableRecords([$day])
        ->assertCanNotSeeTableRecords([$before, $after]);
});

it('excludes a task without a due date by any due bound and lists it with no due filter', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $undated = taskFilterTask($project, ['due_date' => null]);
    $dated = taskFilterTask($project, ['due_date' => '2030-03-10']);

    Livewire::test(ListTasks::class)
        ->assertCanSeeTableRecords([$undated, $dated])
        ->filterTable('due', ['due_from' => '2030-03-01', 'due_until' => null])
        ->assertCanSeeTableRecords([$dated])
        ->assertCanNotSeeTableRecords([$undated])
        ->filterTable('due', ['due_from' => null, 'due_until' => '2030-03-31'])
        ->assertCanSeeTableRecords([$dated])
        ->assertCanNotSeeTableRecords([$undated]);
});

it('shows the Czech empty state when the filters match nothing', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    taskFilterTask($project, ['status' => 'planned']);

    Livewire::test(ListTasks::class)
        ->filterTable('status', 'done')
        ->assertSee('Zatím tu nejsou žádné úkoly');
});

it('orders by update time descending and by id descending for equal timestamps, on every page', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $tasks = collect(range(1, 7))->map(static fn (): Task => taskFilterTask($project));
    $same = '2030-01-01 10:00:00+00';

    DB::table('tasks')->whereIn('id', $tasks->pluck('id')->all())->update(['updated_at' => $same]);

    $ordered = $tasks->sortByDesc('id')->values();

    Livewire::test(ListTasks::class)
        ->set('tableRecordsPerPage', 5)
        ->assertCanSeeTableRecords($ordered->take(5), inOrder: true)
        ->assertCanNotSeeTableRecords($ordered->slice(5)->values())
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($ordered->slice(5)->values(), inOrder: true);
});

it('puts the most recently updated task first', function (): void {
    $project = taskFilterProject(Client::factory()->create(), 'AAA');
    $older = taskFilterTask($project);
    $newer = taskFilterTask($project);

    DB::table('tasks')->where('id', $older->id)->update(['updated_at' => '2030-01-01 10:00:00+00']);
    DB::table('tasks')->where('id', $newer->id)->update(['updated_at' => '2030-01-02 10:00:00+00']);

    Livewire::test(ListTasks::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('never shows a task tag to a Partner through the Tag query', function (): void {
    $client = Client::factory()->create();
    $project = app(PartnerContext::class)->runAsSystem(static fn (): Project => Project::factory()->create([
        'client_id' => $client->id,
        'key' => Canary::projectKey(),
        'client_visible' => true,
    ]));
    $task = app(PartnerContext::class)->runAsSystem(static fn (): Task => taskFilterTask($project));
    app(PartnerContext::class)->runAsSystem(static fn () => $task->attachTag(Canary::canary('task_tag'), TagType::Task->value));

    $this->actingAs(Canary::partnerFor($client->id));

    expect(Tag::query()->count())->toBe(0);

    $this->actingAs($this->admin);

    expect(Tag::query()->where('type', TagType::Task->value)->count())->toBe(1);
});

it('keeps the tags of an archived task and detaches them only on a force delete', function (): void {
    $task = taskFilterTask(taskFilterProject(Client::factory()->create(), 'AAA'));
    $task->attachTag('example-label', TagType::Task->value);
    $count = static fn (): int => DB::table('taggables')->where('taggable_type', 'task')->where('taggable_id', $task->id)->count();

    $task->delete();

    expect($count())->toBe(1);

    Task::withTrashed()->findOrFail($task->id)->forceDelete();

    expect($count())->toBe(0);
});
