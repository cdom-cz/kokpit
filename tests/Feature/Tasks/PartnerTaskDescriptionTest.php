<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Text\RichText;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\RelationManagers\TaskHistoryRelationManager;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * A Partner edits the description of an own task (gap G-05-5, decision D-16):
 * only the description, only in the statuses Planned and To clarify, always
 * cleaned, and never written into the log. Every name and text is fictional and
 * assembled at runtime.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function partnerDescSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored row of the task, read around every scope.
 */
function partnerDescRow(Task $task): Task
{
    return partnerDescSystem(static fn (): Task => Task::query()->withTrashed()->findOrFail($task->id));
}

/**
 * The activity rows of the task, read as system.
 *
 * @return list<Activity>
 */
function partnerDescHistory(Task $task): array
{
    return partnerDescSystem(static fn (): array => Activity::query()
        ->where('subject_id', $task->id)
        ->orderBy('created_at')
        ->get()
        ->all());
}

/**
 * The history rows of the description edit only.
 *
 * @return list<Activity>
 */
function partnerDescEdits(Task $task): array
{
    return array_values(array_filter(
        partnerDescHistory($task),
        static fn (Activity $row): bool => $row->event === 'description_changed',
    ));
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partnerA = Canary::partnerFor($this->clientA);
    $this->partnerA2 = Canary::partnerFor($this->clientA);
    $this->partnerB = Canary::partnerFor($this->clientB);

    $this->project = partnerDescSystem(fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($this->clientA), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));

    // A task raised by Partner A: requester Partner A, assignee the Admin, status Planned (editable, D-16).
    $this->actingAs($this->partnerA);
    $this->task = app(CreateTask::class)->handle($this->partnerA, $this->project, [
        'title' => Canary::canary('task'),
        'description' => '<p>First '.Canary::canary('text').' words</p>',
    ]);
});

it('lets a Partner edit the description of an own task from the task page and logs it in the task history', function (): void {
    $before = partnerDescRow($this->task);
    $rowsBefore = count(partnerDescHistory($this->task));
    $typed = '<p>Edited '.Canary::canary('one').'</p><p>Second '.Canary::canary('two').' paragraph</p>';

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->assertActionVisible('editDescription')
        ->callAction('editDescription', ['description' => $typed])
        ->assertHasNoActionErrors();

    $after = partnerDescRow($this->task);

    expect($after->description)->toBe(RichText::clean($typed))
        ->and($after->description)->not->toBe($before->description)
        ->and($after->title)->toBe($before->title)
        ->and($after->status)->toBe($before->status)
        ->and($after->priority)->toBe($before->priority)
        ->and($after->assignee_id)->toBe($before->assignee_id)
        ->and($after->requester_id)->toBe($before->requester_id)
        ->and($after->start_date?->toDateString())->toBe($before->start_date?->toDateString())
        ->and($after->due_date?->toDateString())->toBe($before->due_date?->toDateString())
        ->and($after->position)->toBe($before->position)
        ->and($after->escalated_at)->toBe($before->escalated_at)
        ->and($after->escalated_by_id)->toBe($before->escalated_by_id);

    $edits = partnerDescEdits($this->task);

    expect(count(partnerDescHistory($this->task)))->toBe($rowsBefore + 1)
        ->and($edits)->toHaveCount(1)
        ->and($edits[0]->causer_id)->toBe($this->partnerA->id)
        ->and($edits[0]->attribute_changes?->get('attributes', []) ?? [])->toBe([]);

    $this->actingAs($this->admin);

    Livewire::test(TaskHistoryRelationManager::class, ['ownerRecord' => partnerDescRow($this->task), 'pageClass' => EditTask::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($edits)
        ->assertSee(__('kokpit.activity.events.description_changed'));
});

it('keeps the header actions of the Partner task page to the description edit and the two escalation actions', function (): void {
    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference]);
    $names = array_map(static fn ($action): string => (string) $action->getName(), array_values($page->instance()->getCachedHeaderActions()));

    expect($names)->toBe(['editDescription', 'escalate', 'clearEscalation']);

    $page->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete');

    expect(array_keys(PartnerTaskResource::getPages()))->toBe(['index', 'create', 'view']);
    $this->get('/admin/my-tasks/'.$this->task->reference.'/edit')->assertNotFound();
});
