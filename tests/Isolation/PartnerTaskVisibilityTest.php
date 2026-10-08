<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Filament\Pages\TaskBoardPage;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\CreatePartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ListPartnerTasks;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Support\TaskColumns;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * What a Partner sees of tasks and what a Partner can change (TA-07, KB-03, D-13,
 * research A1 and A6). The Partner surface is the pinned builders of TaskColumns
 * and nothing else. Every name and canary is fictional and assembled at runtime.
 */

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function partnerTaskVisSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client-visible project of the client in a system run.
 *
 * @param  array<string, mixed>  $attributes
 */
function partnerTaskVisProject(string $clientId, array $attributes = []): Project
{
    return partnerTaskVisSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
        ...$attributes,
    ]));
}

/**
 * A task through the real Action, written by the Admin.
 */
function partnerTaskVisTask(User $admin, Project $project, string $title): Task
{
    return partnerTaskVisSystem(static fn (): Task => app(CreateTask::class)->handle($admin, $project, ['title' => $title]));
}

/**
 * The names of the components of a list.
 *
 * @param  iterable<mixed>  $components
 * @return list<string>
 */
function partnerTaskVisNames(iterable $components): array
{
    $names = [];

    foreach ($components as $component) {
        $names[] = $component->getName();
    }

    return $names;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->projectA = partnerTaskVisProject($this->clientA);
    $this->projectB = partnerTaskVisProject($this->clientB);
    $this->hiddenA = partnerTaskVisProject($this->clientA, ['client_visible' => false]);
    $this->partnerA = Canary::partnerFor($this->clientA);

    $this->canaryTitle = Canary::canary('title');
    $this->taskA = partnerTaskVisTask($this->admin, $this->projectA, $this->canaryTitle);
});

describe('pinned builders', function (): void {
    it('builds exactly the pinned Partner columns in the Partner list', function (): void {
        $this->actingAs($this->partnerA);

        $columns = Livewire::test(ListPartnerTasks::class)->instance()->getTable()->getColumns();

        expect(array_keys($columns))->toBe(TaskColumns::PARTNER_COLUMN_NAMES)
            ->and(partnerTaskVisNames(TaskColumns::partnerColumns()))->toBe(TaskColumns::PARTNER_COLUMN_NAMES);
    });

    it('builds exactly the pinned Partner entries in the Partner infolist', function (): void {
        $schema = PartnerTaskResource::infolist(Schema::make());

        expect(partnerTaskVisNames($schema->getComponents(withHidden: true)))->toBe(TaskColumns::PARTNER_ENTRY_NAMES)
            ->and(partnerTaskVisNames(TaskColumns::partnerEntries()))->toBe(TaskColumns::PARTNER_ENTRY_NAMES);
    });

    it('names no tag, checklist, billing, estimate, rate, price, history or internal key in the pinned lists', function (): void {
        $names = [...TaskColumns::PARTNER_COLUMN_NAMES, ...TaskColumns::PARTNER_ENTRY_NAMES];

        foreach ($names as $name) {
            expect(preg_match('/tag|checklist|billing|estimate|rate|price|money|history|activity|internal|note|cost|budget/i', $name))->toBe(0, "{$name} must not be on a Partner surface");
        }
    });

    it('searches only the reference and the title', function (): void {
        $this->actingAs($this->partnerA);

        $columns = Livewire::test(ListPartnerTasks::class)->instance()->getTable()->getColumns();
        $searchable = array_keys(array_filter($columns, static fn ($column): bool => $column->isSearchable()));

        expect($searchable)->toBe(['reference', 'title']);
    });
});

describe('what the HTML reveals', function (): void {
    it('shows no tag, checklist item, billing value or internal comment of a task, neither in the list nor on the page', function (): void {
        $tag = Canary::canary('tag');
        $item = Canary::canary('item');
        $note = Canary::canary('note');
        $internal = Canary::canary('internal');

        partnerTaskVisSystem(function () use ($tag, $item, $note, $internal): void {
            $this->taskA->syncTagsWithType([$tag], TagType::Task->value);
            $this->taskA->checklistItems()->create(['text' => $item, 'position' => 1]);
            $this->taskA->billing()->create(['billing_type' => TaskBillingType::Inherit, 'internal_note' => $note]);
            app(AddTaskComment::class)->handle($this->admin, $this->taskA, '<p>'.$internal.'</p>', internal: true);
        });

        // The canaries exist: the Admin page shows the tag, the Admin edit page the checklist item and the note.
        $this->actingAs($this->admin);
        $adminPage = (string) $this->get('/admin/tasks/'.$this->taskA->reference)->assertOk()->getContent();
        $adminEdit = (string) $this->get('/admin/tasks/'.$this->taskA->reference.'/edit')->assertOk()->getContent();
        expect($adminPage)->toContain($tag)->and($adminEdit)->toContain($item)->toContain($note);

        $this->actingAs($this->partnerA);
        $list = (string) $this->get('/admin/my-tasks')->assertOk()->getContent();
        $page = (string) $this->get('/admin/my-tasks/'.$this->taskA->reference)->assertOk()->getContent();

        expect($list)->toContain($this->canaryTitle)
            ->and($page)->toContain($this->canaryTitle);

        foreach ([$tag, $item, $note, $internal] as $canary) {
            expect($list)->not->toContain($canary)
                ->and($page)->not->toContain($canary);
        }
    });

    it('shows no Admin-only label on the Partner list and page', function (): void {
        $this->actingAs($this->partnerA);

        $bodies = [
            (string) $this->get('/admin/my-tasks')->assertOk()->getContent(),
            (string) $this->get('/admin/my-tasks/'.$this->taskA->reference)->assertOk()->getContent(),
        ];

        foreach ($bodies as $body) {
            foreach (['Štítky', 'Kontrolní seznam', 'Fakturace', 'Hodinová sazba', 'Pevná cena', 'Odhad', 'Interní', 'Historie změn'] as $label) {
                expect($body)->not->toContain($label);
            }
        }
    });
});

describe('what a Partner can open', function (): void {
    it('answers 404 to the page of a client B task, of a task of an own project that is not client-visible and of an archived own task', function (): void {
        $foreign = partnerTaskVisTask($this->admin, $this->projectB, Canary::canary('foreign'));
        $hidden = partnerTaskVisTask($this->admin, $this->hiddenA, Canary::canary('hidden'));
        $archived = partnerTaskVisTask($this->admin, $this->projectA, Canary::canary('archived'));
        partnerTaskVisSystem(static fn () => $archived->delete());

        $this->actingAs($this->partnerA);

        foreach ([$foreign, $hidden, $archived] as $task) {
            $response = $this->get('/admin/my-tasks/'.$task->reference);
            $response->assertNotFound();

            expect((string) $response->getContent())->not->toContain($task->title);
        }

        $this->get('/admin/my-tasks/'.$this->taskA->reference)->assertOk();
    });

    it('lists only the tasks of the own visible projects in the Livewire table', function (): void {
        $foreign = partnerTaskVisTask($this->admin, $this->projectB, Canary::canary('foreign'));
        $hidden = partnerTaskVisTask($this->admin, $this->hiddenA, Canary::canary('hidden'));
        $this->actingAs($this->partnerA);

        Livewire::test(ListPartnerTasks::class)
            ->assertCanSeeTableRecords([$this->taskA])
            ->assertCanNotSeeTableRecords([$foreign, $hidden])
            ->searchTable($foreign->title)
            ->assertCanNotSeeTableRecords([$this->taskA, $foreign, $hidden]);
    });
});

describe('nothing to change', function (): void {
    it('has no record action, bulk action, reorder or export on the list and no header action but creating', function (): void {
        $this->actingAs($this->partnerA);

        $table = Livewire::test(ListPartnerTasks::class)->instance()->getTable();

        expect($table->getFlatRecordActions())->toBe([])
            ->and($table->getFlatBulkActions())->toBe([])
            ->and($table->isReorderable())->toBeFalse()
            ->and($table->getHeaderActions())->toBe([]);

        $header = Livewire::test(ListPartnerTasks::class)->instance()->getCachedHeaderActions();

        expect(partnerTaskVisNames($header))->toBe(['create']);
    });

    it('has no edit or delete action on the task page and no edit route', function (): void {
        $this->actingAs($this->partnerA);

        $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->taskA->reference]);

        expect($page->instance()->getCachedHeaderActions())->toBe([]);
        $page->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete');

        expect(array_keys(PartnerTaskResource::getPages()))->toBe(['index', 'create', 'view']);
        $this->get('/admin/my-tasks/'.$this->taskA->reference.'/edit')->assertNotFound();
    });

    it('exposes no moveCard method on a Partner page and refuses the board to a Partner', function (): void {
        $this->actingAs($this->partnerA);

        foreach ([ListPartnerTasks::class, CreatePartnerTask::class, ViewPartnerTask::class] as $page) {
            expect(method_exists($page, 'moveCard'))->toBeFalse("{$page} must not have a moveCard method");
        }

        expect(static fn () => Livewire::test(ListPartnerTasks::class)->call('moveCard', 'forged', 0, 'planned'))
            ->toThrow(MethodNotFoundException::class);

        $this->get('/admin/task-board')->assertForbidden();
        Livewire::test(TaskBoardPage::class)->assertForbidden();
    });

    it('stores a task with the defaults when the create payload is forged with status, priority, people and tags', function (): void {
        $this->actingAs($this->partnerA);
        $title = Canary::canary('forged');

        Livewire::test(CreatePartnerTask::class)
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('priority')
            ->assertFormFieldDoesNotExist('assignee_id')
            ->assertFormFieldDoesNotExist('requester_id')
            ->assertFormFieldDoesNotExist('tags')
            ->fillForm(['project_id' => $this->projectA->id, 'title' => $title])
            ->set('data.status', 'done')
            ->set('data.priority', 'urgent')
            ->set('data.assignee_id', $this->partnerA->id)
            ->set('data.requester_id', $this->admin->id)
            ->set('data.tags', ['forged'])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = partnerTaskVisSystem(static fn (): Task => Task::query()->where('title', $title)->firstOrFail());

        expect($task->status->value)->toBe('planned')
            ->and($task->priority->value)->toBe('normal')
            ->and($task->assignee_id)->toBe($this->admin->id)
            ->and($task->requester_id)->toBe($this->partnerA->id)
            ->and($task->tagsWithType(TagType::Task->value)->count())->toBe(0);
    });
});
