<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Tag;
use App\Domain\Tasks\Models\Task;
use App\Filament\Pages\TaskBoardPage;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The global kanban board (KB-01, KB-02, KB-03): six status columns ordered by
 * position, a drop that stores status and position at once, and an Admin-only
 * page. Every name and key is fictional.
 */

/**
 * A project of the given client with the given key, written through the domain Action.
 */
function taskBoardProject(?Client $client = null, string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle($client ?? Client::factory()->create(), [
        'name' => 'Example board project '.$key,
        'key' => $key,
        'billing_type' => 'hourly',
    ]);
}

/**
 * A task of the project with the given status, appended to the end of its column.
 *
 * @param  array<string, mixed>  $attributes
 */
function taskBoardTask(Project $project, string $title, string $status = 'planned', array $attributes = []): Task
{
    return Task::factory()->create(['project_id' => $project->id, 'title' => $title, 'status' => $status, ...$attributes]);
}

/**
 * The titles of one column in stored order.
 *
 * @return list<string>
 */
function taskBoardColumn(string $status): array
{
    return Task::query()->where('status', $status)->orderBy('position')->orderBy('id')->pluck('title')->all();
}

/**
 * The stored positions of the active, non-done tasks of one column.
 *
 * @return list<int>
 */
function taskBoardPositions(string $status): array
{
    return array_map(intval(...), Task::query()->where('status', $status)->orderBy('position')->pluck('position')->all());
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('renders six columns, one per status, with the cards in position order', function (): void {
    $project = taskBoardProject();
    taskBoardTask($project, 'Example first card');
    taskBoardTask($project, 'Example second card');
    taskBoardTask($project, 'Example third card');

    $html = (string) $this->get('/admin/task-board')->assertOk()->getContent();

    foreach (ProjectStatus::cases() as $status) {
        expect($html)->toContain('wire:sort:group-id="'.$status->value.'"');
        expect($html)->toContain($status->getLabel());
    }

    expect(substr_count($html, 'wire:sort="moveCard"'))->toBe(6);

    $positions = array_map(fn (ProjectStatus $status): int|false => strpos($html, 'wire:sort:group-id="'.$status->value.'"'), ProjectStatus::cases());
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);

    Livewire::test(TaskBoardPage::class)
        ->assertSeeInOrder(['Example first card', 'Example second card', 'Example third card']);
});

it('stores a card dropped into another column at the dropped index and keeps it after a reload', function (): void {
    $project = taskBoardProject();
    $moved = taskBoardTask($project, 'Example moved card');
    taskBoardTask($project, 'Example progress one', 'in_progress');
    taskBoardTask($project, 'Example progress two', 'in_progress');
    taskBoardTask($project, 'Example progress three', 'in_progress');
    taskBoardTask($project, 'Example planned stay');

    Livewire::test(TaskBoardPage::class)->call('moveCard', $moved->id, 1, 'in_progress');

    expect(taskBoardColumn('in_progress'))->toBe([
        'Example progress one',
        'Example moved card',
        'Example progress two',
        'Example progress three',
    ])
        ->and($moved->fresh()->status)->toBe(ProjectStatus::InProgress)
        ->and(taskBoardPositions('in_progress'))->toBe([0, 1, 2, 3])
        ->and(taskBoardColumn('planned'))->toBe(['Example planned stay']);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    Livewire::test(TaskBoardPage::class)
        ->assertSeeInOrder(['Example progress one', 'Example moved card', 'Example progress two', 'Example progress three']);
});

it('reorders a card inside its column', function (): void {
    $project = taskBoardProject();
    $first = taskBoardTask($project, 'Example one');
    taskBoardTask($project, 'Example two');
    taskBoardTask($project, 'Example three');

    Livewire::test(TaskBoardPage::class)->call('moveCard', $first->id, 2, 'planned');

    expect(taskBoardColumn('planned'))->toBe(['Example two', 'Example three', 'Example one'])
        ->and(taskBoardPositions('planned'))->toBe([0, 1, 2]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('fires exactly one updated event with the status for a cross-column move and none for a reorder', function (): void {
    $project = taskBoardProject();
    $card = taskBoardTask($project, 'Example event card');
    taskBoardTask($project, 'Example event target', 'in_review');

    $events = [];
    Event::listen('eloquent.updated: '.Task::class, function (Task $model) use (&$events): void {
        $events[] = array_keys($model->getChanges());
    });

    Livewire::test(TaskBoardPage::class)->call('moveCard', $card->id, 0, 'in_review');

    expect($events)->toHaveCount(1)
        ->and($events[0])->toContain('status');

    $events = [];

    Livewire::test(TaskBoardPage::class)->call('moveCard', $card->id, 1, 'in_review');

    expect($events)->toBe([])
        ->and(taskBoardColumn('in_review'))->toBe(['Example event target', 'Example event card']);
});

it('leaves updated_at alone when a card is only reordered', function (): void {
    $project = taskBoardProject();
    $first = taskBoardTask($project, 'Example stamp one');
    $second = taskBoardTask($project, 'Example stamp two');

    $past = Carbon::parse('2026-01-02 03:04:05');
    DB::table('tasks')->update(['updated_at' => $past]);

    Livewire::test(TaskBoardPage::class)->call('moveCard', $first->id, 1, 'planned');

    expect(taskBoardColumn('planned'))->toBe(['Example stamp two', 'Example stamp one'])
        ->and($first->fresh()->updated_at->equalTo($past))->toBeTrue()
        ->and($second->fresh()->updated_at->equalTo($past))->toBeTrue();
});

it('refuses the board page to a Partner as a request and as a component', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);

    $this->actingAs($partner)->get('/admin/task-board')->assertForbidden();

    Livewire::actingAs($partner)->test(TaskBoardPage::class)->assertForbidden();
});

it('orders tasks by position without changing how the tags package orders its models', function (): void {
    $task = Task::factory()->make();

    expect($task->determineOrderColumnName())->toBe('position')
        ->and($task->shouldSortWhenCreating())->toBeFalse()
        ->and(config('eloquent-sortable.ignore_timestamps'))->toBeTrue()
        ->and((new Tag)->determineOrderColumnName())->toBe('order_column')
        ->and((new Tag)->shouldSortWhenCreating())->toBeTrue();
});
