<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Actions\MoveTask;
use App\Domain\Tasks\Board\BoardFilters;
use App\Domain\Tasks\Models\Task;
use App\Filament\Pages\TaskBoardPage;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Attributes\Url;
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

/*
 * Filters, filter-aware drops, the Done cap and the guard order of a move.
 */

/**
 * The tag id of a task tag by name, after attaching it to a task.
 */
function taskBoardTagId(Task $task, string $name): string
{
    $task->attachTag($name, TagType::Task->value);

    return (string) Tag::query()->where('type', TagType::Task->value)->where('name->cs', $name)->orWhere('name->en', $name)->firstOrFail()->getKey();
}

/**
 * Two clients with one project each, a hidden column of three planned cards
 * A1, B1, A2 and a client A card X waiting in another column.
 *
 * @return array{clientA: Client, clientB: Client, x: Task}
 */
function taskBoardFilteredFixture(): array
{
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();
    $projectA = taskBoardProject($clientA, 'AAA');
    $projectB = taskBoardProject($clientB, 'BBB');

    taskBoardTask($projectA, 'Example A1');
    taskBoardTask($projectB, 'Example B1');
    taskBoardTask($projectA, 'Example A2');

    return ['clientA' => $clientA, 'clientB' => $clientB, 'x' => taskBoardTask($projectA, 'Example X', 'in_progress')];
}

it('narrows the board by client, assignee, tag and priority and combines the filters', function (): void {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();
    $projectA = taskBoardProject($clientA, 'AAA');
    $projectB = taskBoardProject($clientB, 'BBB');
    $someone = User::factory()->create();

    $matching = taskBoardTask($projectA, 'Example matching card', 'planned', ['assignee_id' => $someone->id, 'priority' => 'high']);
    $otherClient = taskBoardTask($projectB, 'Example other client card', 'planned', ['assignee_id' => $someone->id, 'priority' => 'high']);
    $otherPerson = taskBoardTask($projectA, 'Example other person card', 'planned', ['priority' => 'high']);
    $otherPriority = taskBoardTask($projectA, 'Example other priority card', 'planned', ['assignee_id' => $someone->id]);
    $tagId = taskBoardTagId($matching, 'example-board-label');

    Livewire::withQueryParams(['clientFilter' => $clientA->id])->test(TaskBoardPage::class)
        ->assertSee('Example matching card')->assertSee('Example other person card')
        ->assertDontSee('Example other client card');

    Livewire::withQueryParams(['assigneeFilter' => $someone->id])->test(TaskBoardPage::class)
        ->assertSee('Example matching card')->assertSee('Example other client card')
        ->assertDontSee('Example other person card');

    Livewire::withQueryParams(['tagFilter' => $tagId])->test(TaskBoardPage::class)
        ->assertSee('Example matching card')
        ->assertDontSee('Example other client card')->assertDontSee('Example other person card');

    Livewire::withQueryParams(['priorityFilter' => 'high'])->test(TaskBoardPage::class)
        ->assertSee('Example matching card')->assertSee('Example other person card')
        ->assertDontSee('Example other priority card');

    Livewire::withQueryParams(['clientFilter' => $clientA->id, 'assigneeFilter' => $someone->id, 'priorityFilter' => 'high'])->test(TaskBoardPage::class)
        ->assertSee('Example matching card')
        ->assertDontSee('Example other client card')->assertDontSee('Example other person card')->assertDontSee('Example other priority card');

    expect([$otherClient->id, $otherPerson->id, $otherPriority->id])->toHaveCount(3);
});

it('binds every filter to the URL query', function (): void {
    foreach (['clientFilter', 'assigneeFilter', 'tagFilter', 'priorityFilter'] as $property) {
        $attributes = (new ReflectionProperty(TaskBoardPage::class, $property))->getAttributes(Url::class);

        expect($attributes)->toHaveCount(1, $property.' must be a #[Url] property');
    }

    $client = Client::factory()->create();

    Livewire::withQueryParams(['clientFilter' => $client->id, 'priorityFilter' => 'urgent'])->test(TaskBoardPage::class)
        ->assertSet('clientFilter', $client->id)
        ->assertSet('priorityFilter', 'urgent');
});

it('ignores a filter value that is no id instead of failing', function (): void {
    $project = taskBoardProject();
    taskBoardTask($project, 'Example still visible');

    Livewire::withQueryParams(['clientFilter' => 'not-an-id', 'priorityFilter' => 'bogus'])->test(TaskBoardPage::class)
        ->assertOk()
        ->assertSee('Example still visible');
});

it('drops a card after the visible neighbour on a filtered board even when hidden cards sit between', function (int $index, array $expected): void {
    ['clientA' => $clientA, 'x' => $x] = taskBoardFilteredFixture();

    Livewire::withQueryParams(['clientFilter' => $clientA->id])->test(TaskBoardPage::class)
        ->call('moveCard', $x->id, $index, 'planned');

    expect(taskBoardColumn('planned'))->toBe($expected)
        ->and(taskBoardPositions('planned'))->toBe([0, 1, 2, 3]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
})->with([
    'between the visible cards' => [1, ['Example A1', 'Example B1', 'Example X', 'Example A2']],
    'before the first visible card' => [0, ['Example X', 'Example A1', 'Example B1', 'Example A2']],
    'after the last visible card' => [2, ['Example A1', 'Example B1', 'Example A2', 'Example X']],
]);

it('appends a card dropped into an empty visible column to the end of the whole column', function (): void {
    $clientA = Client::factory()->create();
    $projectA = taskBoardProject($clientA, 'AAA');
    $projectB = taskBoardProject(Client::factory()->create(), 'BBB');
    $x = taskBoardTask($projectA, 'Example X');
    taskBoardTask($projectB, 'Example hidden one', 'in_review');
    taskBoardTask($projectB, 'Example hidden two', 'in_review');

    Livewire::withQueryParams(['clientFilter' => $clientA->id])->test(TaskBoardPage::class)
        ->call('moveCard', $x->id, 0, 'in_review');

    expect(taskBoardColumn('in_review'))->toBe(['Example hidden one', 'Example hidden two', 'Example X'])
        ->and(taskBoardPositions('in_review'))->toBe([0, 1, 2]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('shows only the most recent done tasks up to the configured limit with an N of M count', function (): void {
    config(['kokpit.board.done_limit' => 3]);
    $project = taskBoardProject();

    foreach ([1, 2, 3, 4, 5] as $n) {
        taskBoardTask($project, 'Example done '.$n, 'done', ['completed_at' => now()->subHours($n)]);
    }

    Livewire::test(TaskBoardPage::class)
        ->assertSee('Example done 1')->assertSee('Example done 2')->assertSee('Example done 3')
        ->assertDontSee('Example done 4')->assertDontSee('Example done 5')
        ->assertSee('3 / 5');

});

it('sets completion and position 0 when a card enters Done and clears the completion when it leaves', function (): void {
    $project = taskBoardProject();
    $card = taskBoardTask($project, 'Example finishing card');
    taskBoardTask($project, 'Example waiting card');

    Livewire::test(TaskBoardPage::class)->call('moveCard', $card->id, 0, 'done');

    $card->refresh();
    expect($card->status)->toBe(ProjectStatus::Done)
        ->and($card->completed_at)->not->toBeNull()
        ->and($card->position)->toBe(0);

    Livewire::test(TaskBoardPage::class)->call('moveCard', $card->id, 1, 'planned');

    $card->refresh();
    expect($card->status)->toBe(ProjectStatus::Planned)
        ->and($card->completed_at)->toBeNull()
        ->and(taskBoardColumn('planned'))->toBe(['Example waiting card', 'Example finishing card'])
        ->and(taskBoardPositions('planned'))->toBe([0, 1]);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('changes nothing for a drop inside the Done column', function (): void {
    $project = taskBoardProject();
    $first = taskBoardTask($project, 'Example done first', 'done', ['completed_at' => now()->subHour()]);
    taskBoardTask($project, 'Example done second', 'done', ['completed_at' => now()->subHours(2)]);
    $before = $first->fresh()->completed_at;

    Livewire::test(TaskBoardPage::class)->call('moveCard', $first->id, 1, 'done');

    expect($first->fresh()->completed_at->equalTo($before))->toBeTrue();
});

it('answers 422 for a forged status and changes nothing', function (): void {
    $card = taskBoardTask(taskBoardProject(), 'Example guarded card');
    $before = DB::table('tasks')->get()->toArray();

    Livewire::test(TaskBoardPage::class)->call('moveCard', $card->id, 0, 'archived')->assertStatus(422);

    expect(DB::table('tasks')->get()->toArray())->toEqual($before);
});

it('answers 404 for an unknown id, a malformed id and an archived task', function (): void {
    $project = taskBoardProject();
    $archived = taskBoardTask($project, 'Example archived card');
    $archived->delete();

    Livewire::test(TaskBoardPage::class)
        ->call('moveCard', '01a11da2-6a80-7239-b49e-fc913bf8e2bb', 0, 'planned')->assertNotFound();

    Livewire::test(TaskBoardPage::class)
        ->call('moveCard', 'not-an-id', 0, 'planned')->assertNotFound();

    Livewire::test(TaskBoardPage::class)
        ->call('moveCard', $archived->id, 0, 'planned')->assertNotFound();

    expect(Task::query()->find($archived->id))->toBeNull()
        ->and(Task::query()->withTrashed()->find($archived->id)->status)->toBe(ProjectStatus::Planned);
});

it('refuses the mover to a Partner: another client task is not found, an own client task is forbidden, nothing changes', function (): void {
    [$idA, $idB] = Canary::twoClients();
    $projectA = taskBoardProject(Client::query()->findOrFail($idA), 'AAA');
    $projectB = taskBoardProject(Client::query()->findOrFail($idB), 'BBB');
    Project::query()->whereKey([$projectA->id, $projectB->id])->update(['client_visible' => true]);
    $own = taskBoardTask($projectA, 'Example own client card');
    $foreign = taskBoardTask($projectB, 'Example other client card');
    $before = DB::table('tasks')->get()->toArray();

    $partner = Canary::partnerFor($idA);
    $this->actingAs($partner);

    expect(fn () => app(MoveTask::class)->handle($partner, $foreign->id, 0, ProjectStatus::Done, BoardFilters::none()))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(MoveTask::class)->handle($partner, $own->id, 0, ProjectStatus::Done, BoardFilters::none()))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('tasks')->get()->toArray())->toEqual($before);
});
