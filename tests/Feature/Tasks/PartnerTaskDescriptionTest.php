<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\UpdateNotificationPreferences;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Text\RichText;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Actions\UpdateTaskDescription;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Notifications\TaskChangedNotification;
use App\Domain\Tasks\Policies\TaskPolicy;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\RelationManagers\TaskHistoryRelationManager;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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

/**
 * Moves the task to a status as the Admin, through the real Action.
 */
function partnerDescStatus(User $admin, Task $task, string $status): Task
{
    return partnerDescSystem(static fn (): Task => app(UpdateTask::class)->handle($admin, Task::query()->findOrFail($task->id), ['status' => $status]));
}

/**
 * A task of a project written by the Admin, optionally a subtask.
 */
function partnerDescAdminTask(User $admin, Project $project, ?Task $parent = null): Task
{
    return partnerDescSystem(static fn (): Task => app(CreateTask::class)->handle($admin, $project, ['title' => Canary::canary('admin_task')], $parent));
}

/**
 * The columns of the stored row that the description edit must not touch, with the tags.
 *
 * @return array<string, mixed>
 */
function partnerDescUntouched(Task $task): array
{
    return partnerDescSystem(static function () use ($task): array {
        $row = Task::query()->withTrashed()->findOrFail($task->id);
        $attributes = $row->getAttributes();
        unset($attributes['description'], $attributes['updated_at']);

        return [...$attributes, '_tags' => $row->tags->pluck('name')->sort()->values()->all()];
    });
}

/**
 * The marker words of the hostile description, none of which may survive.
 *
 * @return list<string>
 */
function partnerDescMarkers(): array
{
    return ['markerAlpha', 'markerBeta', 'markerGamma', 'markerDelta', 'markerEpsilon'];
}

/**
 * A hostile description assembled from fragments at runtime, so no single line of this file looks like a payload constant.
 */
function partnerDescPayload(): string
{
    $lt = '<';

    return implode('', [
        $lt.'scr'.'ipt>window.markerAlpha()'.$lt.'/scr'.'ipt>',
        $lt.'img src="x" on'.'error="markerBeta()">',
        $lt.'a href="java'.'script:markerGamma()">Example link words'.$lt.'/a>',
        $lt.'p style="position:'.'fixed;top:markerDelta" class="markerEpsilon">Benign words'.$lt.'/p>',
    ]);
}

/**
 * The HTML of a Livewire component without its snapshot attribute, which carries the raw state.
 */
function partnerDescHtml(string $html): string
{
    return (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);
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

it('lets any Partner of the client edit the description of a task in an editable status, whatever its requester or assignee', function (string $status): void {
    $subtask = partnerDescAdminTask($this->admin, $this->project, partnerDescRow($this->task));
    partnerDescStatus($this->admin, $this->task, $status);
    partnerDescStatus($this->admin, $subtask, $status);

    $cases = [
        [$this->partnerA, $this->task],
        [$this->partnerA2, $this->task],
        [$this->partnerA2, $subtask],
    ];

    foreach ($cases as [$partner, $task]) {
        $typed = '<p>Edited '.Canary::canary('by_partner').'</p>';
        $this->actingAs($partner);

        Livewire::test(ViewPartnerTask::class, ['record' => $task->reference])
            ->assertActionVisible('editDescription')
            ->callAction('editDescription', ['description' => $typed])
            ->assertHasNoActionErrors();

        expect(partnerDescRow($task)->description)->toBe(RichText::clean($typed))
            ->and(partnerDescEdits($task))->not->toBeEmpty()
            ->and(array_last(partnerDescEdits($task))->causer_id)->toBe($partner->id);
    }
})->with(['planned' => ['planned'], 'to clarify' => ['to_clarify']]);

it('refuses the description edit to a Partner in every other status', function (string $status): void {
    partnerDescStatus($this->admin, $this->task, $status);
    $before = partnerDescRow($this->task);
    $rowsBefore = count(partnerDescHistory($this->task));
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->assertActionHidden('editDescription');

    expect(Gate::forUser($this->partnerA)->allows('editDescription', $before))->toBeFalse()
        ->and(fn () => app(UpdateTaskDescription::class)->handle($this->partnerA, $before, '<p>Example refused text</p>', UpdateTaskDescription::fingerprint($before->description)))
        ->toThrow(AuthorizationException::class);

    expect(partnerDescRow($this->task)->description)->toBe($before->description)
        ->and(count(partnerDescHistory($this->task)))->toBe($rowsBefore);

    // D-16 restricts the Partner only: the Admin is admitted.
    $this->actingAs($this->admin);
    app(UpdateTaskDescription::class)->handle($this->admin, $before, '<p>Example admin text</p>', UpdateTaskDescription::fingerprint($before->description));

    expect(partnerDescRow($this->task)->description)->toBe('<p>Example admin text</p>');
})->with([
    'in progress' => ['in_progress'],
    'in review' => ['in_review'],
    'ready to release' => ['ready_to_release'],
    'done' => ['done'],
]);

it('keeps a Partner of another client, a hidden project and an archived task out of the description edit', function (): void {
    $hiddenProject = partnerDescSystem(fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($this->clientA), [
        'name' => Canary::canary('hidden'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => false,
    ]));
    $hidden = partnerDescAdminTask($this->admin, $hiddenProject);
    $archived = partnerDescAdminTask($this->admin, $this->project);
    partnerDescSystem(fn () => app(ArchiveTask::class)->handle($this->admin, Task::query()->findOrFail($archived->id)));

    $refused = [
        [$this->partnerB, $this->task, [AuthorizationException::class]],
        [$this->partnerA, $hidden, [AuthorizationException::class]],
        [$this->partnerA, $archived, [AuthorizationException::class, ModelNotFoundException::class]],
    ];

    foreach ($refused as [$partner, $task, $expected]) {
        $before = partnerDescRow($task);
        $rowsBefore = count(partnerDescHistory($task));
        $this->actingAs($partner);

        $this->get('/admin/my-tasks/'.$task->reference)->assertNotFound();

        $thrown = null;

        try {
            app(UpdateTaskDescription::class)->handle($partner, $before, '<p>Example foreign text</p>', UpdateTaskDescription::fingerprint($before->description));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and(in_array($thrown::class, $expected, true))->toBeTrue()
            ->and(partnerDescRow($task)->description)->toBe($before->description)
            ->and(count(partnerDescHistory($task)))->toBe($rowsBefore);
    }

    // An archived task is read-only for everybody: the locked re-read does not find it, even for the Admin.
    $this->actingAs($this->admin);
    $archivedRow = partnerDescRow($archived);

    expect(fn () => app(UpdateTaskDescription::class)->handle($this->admin, $archivedRow, '<p>Example admin text</p>', UpdateTaskDescription::fingerprint($archivedRow->description)))
        ->toThrow(ModelNotFoundException::class)
        ->and(partnerDescRow($archived)->description)->toBe($archivedRow->description);
});

it('changes nothing but the description when the action payload is forged with other task fields', function (): void {
    $foreignProject = partnerDescSystem(fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($this->clientB), [
        'name' => Canary::canary('foreign'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
    $before = partnerDescUntouched($this->task);
    $typed = '<p>Edited '.Canary::canary('forged').'</p>';
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('editDescription', [
            'description' => $typed,
            'title' => 'Forged title',
            'status' => 'done',
            'priority' => 'urgent',
            'assignee_id' => $this->partnerB->id,
            'requester_id' => $this->admin->id,
            'start_date' => '2030-01-01',
            'due_date' => '2030-02-01',
            'tags' => ['forged'],
            'project_id' => $foreignProject->id,
            'escalated_at' => now()->toDateTimeString(),
        ])
        ->assertHasNoActionErrors();

    expect(partnerDescRow($this->task)->description)->toBe(RichText::clean($typed))
        ->and(partnerDescUntouched($this->task))->toBe($before);
});

it('offers only the description editor in the modal and still refuses a Partner the full update', function (): void {
    $this->actingAs($this->partnerA);
    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->mountAction('editDescription');
    $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());

    expect(array_keys($schema->getFlatFields(withHidden: true)))->toBe(['description', 'based_on'])
        ->and(Gate::forUser($this->partnerA)->allows('update', $this->task))->toBeFalse()
        ->and(Gate::forUser($this->partnerA)->allows('editDescription', $this->task))->toBeTrue()
        ->and(fn () => app(UpdateTask::class)->handle($this->partnerA, partnerDescRow($this->task), ['title' => 'Forged title']))
        ->toThrow(AuthorizationException::class);

    expect(TaskPolicy::DESCRIPTION_EDITABLE_STATUSES)->toHaveCount(2);
});

it('stores and shows an edited Partner description without any hostile part (D-10)', function (): void {
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('editDescription', ['description' => partnerDescPayload()])
        ->assertHasNoActionErrors();

    $stored = (string) partnerDescRow($this->task)->description;
    $page = partnerDescHtml((string) $this->get('/admin/my-tasks/'.$this->task->reference)->assertOk()->getContent());

    $this->actingAs($this->admin);
    $adminPage = partnerDescHtml((string) $this->get('/admin/tasks/'.$this->task->reference)->assertOk()->getContent());

    foreach ([$stored, $page, $adminPage] as $text) {
        expect($text)->toContain('Benign words')->toContain('Example link words');

        foreach (partnerDescMarkers() as $marker) {
            expect($text)->not->toContain($marker);
        }
    }

    expect($stored)->not->toContain('<script')->not->toContain('<img')->not->toContain('style=')->not->toContain('javascript:');
});

it('refuses an over-long description as a field error and writes nothing', function (): void {
    $before = partnerDescRow($this->task);
    $rowsBefore = count(partnerDescHistory($this->task));
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('editDescription', ['description' => str_repeat('a', RichText::MAX_LENGTH + 1)])
        ->assertHasActionErrors(['description' => __('kokpit.tasks.errors.description_too_long')]);

    expect(partnerDescRow($this->task)->description)->toBe($before->description)
        ->and(count(partnerDescHistory($this->task)))->toBe($rowsBefore);
});

it('clears the description when the editor is emptied', function (): void {
    $rowsBefore = count(partnerDescHistory($this->task));
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('editDescription', ['description' => '<p></p>'])
        ->assertHasNoActionErrors();

    expect(partnerDescRow($this->task)->description)->toBeNull()
        ->and(partnerDescEdits($this->task))->toHaveCount(1)
        ->and(count(partnerDescHistory($this->task)))->toBe($rowsBefore + 1);
});

it('writes no description text into the history row', function (): void {
    $oldWord = (string) preg_replace('/<[^>]*>/', '', (string) partnerDescRow($this->task)->description);
    $newWord = Canary::canary('newtext');
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('editDescription', ['description' => '<p>'.$newWord.'</p>'])
        ->assertHasNoActionErrors();

    $edits = partnerDescEdits($this->task);

    expect($edits)->toHaveCount(1);

    foreach (partnerDescHistory($this->task) as $row) {
        $serialised = json_encode($row->getAttributes(), JSON_THROW_ON_ERROR);

        expect($serialised)->not->toContain($newWord)->not->toContain($oldWord);
    }

    // The log is closed to a Partner: not one activity row is readable.
    expect(Activity::query()->count())->toBe(0);
});

it('refuses a save based on a description that changed since the modal opened', function (): void {
    $this->actingAs($this->partnerA);
    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->mountAction('editDescription');

    // The Admin edits the description while the Partner's editor is open.
    $adminText = '<p>Admin '.Canary::canary('meanwhile').'</p>';
    partnerDescSystem(fn (): Task => app(UpdateTask::class)->handle($this->admin, Task::query()->findOrFail($this->task->id), ['description' => $adminText]));
    $rowsBefore = count(partnerDescEdits($this->task));

    $page->setActionData(['description' => '<p>Partner text</p>'])
        ->callMountedAction()
        ->assertHasActionErrors(['description' => __('kokpit.tasks.errors.description_stale')]);

    expect(partnerDescRow($this->task)->description)->toBe($adminText)
        ->and(partnerDescEdits($this->task))->toHaveCount($rowsBefore);

    // The Action called directly with the fingerprint of another text is refused the same way.
    $row = partnerDescRow($this->task);

    try {
        app(UpdateTaskDescription::class)->handle($this->partnerA, $row, '<p>Partner text</p>', UpdateTaskDescription::fingerprint('<p>Some other text</p>'));
        $this->fail('The stale save was accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['description'])
            ->and($e->errors()['description'])->toBe([__('kokpit.tasks.errors.description_stale')]);
    }

    expect(partnerDescRow($this->task)->description)->toBe($adminText);
});

it('accepts a save after an unrelated change of the task', function (): void {
    $this->actingAs($this->partnerA);
    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->mountAction('editDescription');

    // Priority and a move between the two editable statuses do not touch the description.
    partnerDescSystem(fn (): Task => app(UpdateTask::class)->handle($this->admin, Task::query()->findOrFail($this->task->id), ['priority' => 'high', 'status' => 'to_clarify']));
    $typed = '<p>Edited '.Canary::canary('unrelated').'</p>';

    $page->setActionData(['description' => $typed])->callMountedAction()->assertHasNoActionErrors();

    $row = partnerDescRow($this->task);

    expect($row->description)->toBe(RichText::clean($typed))
        ->and($row->status->value)->toBe('to_clarify')
        ->and($row->priority->value)->toBe('high');
});

it('refuses the save when the status left the editable statuses while the editor was open', function (string $status): void {
    // (1) Action level: the caller holds an instance read while the task was Planned.
    $stale = partnerDescRow($this->task);
    $fingerprint = UpdateTaskDescription::fingerprint($stale->description);
    $this->actingAs($this->partnerA);
    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->mountAction('editDescription');

    // The Admin's own status change writes its history row; only the description edit must add none.
    partnerDescStatus($this->admin, $this->task, $status);
    $rowsBefore = count(partnerDescHistory($this->task));

    try {
        app(UpdateTaskDescription::class)->handle($this->partnerA, $stale, '<p>Example late text</p>', $fingerprint);
        $this->fail('The save after the status change was accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['description'])
            ->and($e->errors()['description'])->toBe([__('kokpit.tasks.errors.description_not_editable')]);
    }

    expect(partnerDescRow($this->task)->description)->toBe($stale->description)
        ->and(count(partnerDescHistory($this->task)))->toBe($rowsBefore);

    // (2) Page level: the action is hidden now, so Filament treats it as disabled and does not run it.
    $page->setActionData(['description' => '<p>Example late text</p>'])->callMountedAction();

    expect(partnerDescRow($this->task)->description)->toBe($stale->description)
        ->and(count(partnerDescHistory($this->task)))->toBe($rowsBefore);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->assertActionHidden('editDescription');
})->with(['in progress' => ['in_progress'], 'done' => ['done']]);

it('writes nothing and logs nothing when the description is saved unchanged', function (): void {
    $stored = partnerDescRow($this->task);
    $rowsBefore = count(partnerDescHistory($this->task));
    $this->actingAs($this->partnerA);
    $this->travelTo(now()->addMinutes(10));

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('editDescription', ['description' => $stored->description])
        ->assertHasNoActionErrors();

    $after = partnerDescRow($this->task);

    expect($after->description)->toBe($stored->description)
        ->and($after->updated_at?->getTimestamp())->toBe($stored->updated_at?->getTimestamp())
        ->and(count(partnerDescHistory($this->task)))->toBe($rowsBefore);

    // The same new text twice is one change: the second save is based on the stored new text.
    $typed = '<p>Edited '.Canary::canary('twice').'</p>';
    $action = app(UpdateTaskDescription::class);
    $action->handle($this->partnerA, partnerDescRow($this->task), $typed, UpdateTaskDescription::fingerprint($stored->description));
    $action->handle($this->partnerA, partnerDescRow($this->task), $typed, UpdateTaskDescription::fingerprint(partnerDescRow($this->task)->description));

    expect(partnerDescEdits($this->task))->toHaveCount(1)
        ->and(count(partnerDescHistory($this->task)))->toBe($rowsBefore + 1);
});

it('filters the activity overview by the description edit', function (): void {
    $this->actingAs($this->partnerA);
    app(UpdateTaskDescription::class)->handle($this->partnerA, partnerDescRow($this->task), '<p>Edited '.Canary::canary('overview').'</p>', UpdateTaskDescription::fingerprint(partnerDescRow($this->task)->description));
    partnerDescStatus($this->admin, $this->task, 'to_clarify');

    $history = partnerDescHistory($this->task);
    $edits = array_values(array_filter($history, static fn (Activity $row): bool => $row->event === 'description_changed'));
    $others = array_values(array_filter($history, static fn (Activity $row): bool => $row->event !== 'description_changed'));
    $events = array_unique(array_map(static fn (Activity $row): string => (string) $row->event, $others));

    expect($edits)->toHaveCount(1)->and($events)->toContain('created')->toContain('updated');

    $this->actingAs($this->admin);

    $overview = Livewire::test(ListActivities::class);
    $options = $overview->instance()->getTable()->getFilter('event')?->getOptions() ?? [];

    // The event is offered by the filter, labelled in Czech, not only accepted as a value.
    expect($options)->toHaveKey('description_changed')
        ->and($options['description_changed'] ?? null)->toBe(__('kokpit.activity.events.description_changed'));

    $overview->filterTable('event', 'description_changed')
        ->assertCanSeeTableRecords($edits)
        ->assertCanNotSeeTableRecords($others);
});

/**
 * The Partner saves a new description through the Action, based on the stored one, signed in as that user.
 */
function partnerDescSave(User $actor, Task $task, string $html): Task
{
    test()->actingAs($actor);

    $row = partnerDescRow($task);

    return app(UpdateTaskDescription::class)->handle($actor, $row, $html, UpdateTaskDescription::fingerprint($row->description));
}

/**
 * Sets the assignee as the Admin through the real Action (it may notify, so call it before Notification::fake()).
 */
function partnerDescAssign(User $admin, Task $task, User $assignee): void
{
    partnerDescSystem(static fn (): Task => app(UpdateTask::class)->handle($admin, Task::query()->findOrFail($task->id), ['assignee_id' => $assignee->id]));
}

/**
 * The change notifications the fake holds for the user.
 *
 * @return Collection<int, mixed>
 */
function partnerDescChanges(User $user): Collection
{
    return Notification::sent($user, TaskChangedNotification::class);
}

it('tells the Admin of a Partner description edit by mail and in the bell with the admin link', function (): void {
    Notification::fake();
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('editDescription', ['description' => '<p>Edited '.Canary::canary('notice').'</p>'])
        ->assertHasNoActionErrors();

    Notification::assertCount(1);
    Notification::assertSentTo(
        $this->admin,
        TaskChangedNotification::class,
        fn (TaskChangedNotification $notification, array $channels): bool => $channels === ['mail', 'database']
            && $notification->event === NotificationEvent::AssignmentChange
            && ! $notification->recipientIsPartner
            && $notification->taskReference === $this->task->reference
            && str_ends_with($notification->url, '/admin/tasks/'.$this->task->reference)
            && $notification->changedLabels === [__('kokpit.tasks.notifications.changed.description', ['actor' => $this->partnerA->name])]
            && $notification->excerpt === null,
    );
    Notification::assertNotSentTo($this->partnerA, TaskChangedNotification::class);
    expect(partnerDescChanges($this->admin))->toHaveCount(1);
});

it('puts no text of the description into the notification of the edit', function (): void {
    $oldWord = Canary::canary('olddesc');
    $newWord = Canary::canary('newdesc');
    $thirdWord = Canary::canary('thirddesc');
    partnerDescSave($this->partnerA, $this->task, '<p>'.$oldWord.'</p>');
    $bellBefore = $this->admin->notifications()->count();

    // The real delivery (sync queue): the stored bell entry holds no description word.
    partnerDescSave($this->partnerA, $this->task, '<p>'.$thirdWord.'</p>');

    $rows = $this->admin->notifications()->get();
    $stored = (string) json_encode($rows->map(static fn ($row): mixed => $row->data)->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($rows->count())->toBe($bellBefore + 1)
        ->and($stored)->toContain('Popis upraven')
        ->and($stored)->not->toContain($oldWord)->not->toContain($thirdWord);

    // The sent notification, rendered with no signed-in user as a worker would.
    Notification::fake();
    partnerDescSave($this->partnerA, $this->task, '<p>'.$newWord.'</p>');

    /** @var TaskChangedNotification $sent */
    $sent = partnerDescChanges($this->admin)->firstOrFail();
    auth()->logout();

    $mail = $sent->toMail($this->admin);
    $html = (string) $mail->render();
    $line = __('kokpit.tasks.notifications.changed.description', ['actor' => $this->partnerA->name]);
    $bell = $sent->toDatabase($this->admin);
    $everything = json_encode([$mail->subject, $mail->introLines, $mail->outroLines, $bell, $sent->changedLabels, $html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($html)->toContain('Popis upraven uživatelem')
        ->and($html)->toContain($this->partnerA->name)
        ->and($mail->subject)->toBe(__('kokpit.tasks.notifications.changed.mail_subject', ['reference' => $this->task->reference, 'title' => $this->task->title]))
        ->and($bell['title'])->toBe(__('kokpit.tasks.notifications.changed.bell_title', ['reference' => $this->task->reference]))
        ->and($bell['body'])->toBe(e($line))
        ->and($everything)->not->toContain($newWord)->not->toContain($oldWord)->not->toContain($thirdWord);
});

it('sends nothing for a refused, stale or unchanged save or an Admin edit through the Action', function (): void {
    $attempt = function (User $actor, Task $based, string $html, ?string $fingerprint = null): ?Throwable {
        $this->actingAs($actor);

        try {
            app(UpdateTaskDescription::class)->handle($actor, $based, $html, $fingerprint ?? UpdateTaskDescription::fingerprint($based->description));
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    };

    // (1) A Partner of another client.
    Notification::fake();
    $thrown = $attempt($this->partnerB, partnerDescRow($this->task), '<p>Example foreign text</p>');
    expect($thrown)->toBeInstanceOf(AuthorizationException::class);
    Notification::assertNothingSent();

    // (2) A status outside D-16.
    partnerDescStatus($this->admin, $this->task, 'in_progress');
    Notification::fake();
    $thrown = $attempt($this->partnerA, partnerDescRow($this->task), '<p>Example late text</p>');
    expect($thrown)->toBeInstanceOf(AuthorizationException::class);
    Notification::assertNothingSent();

    // (3) A status changed while the editor was open (D-16 on the locked row).
    partnerDescStatus($this->admin, $this->task, 'planned');
    $stale = partnerDescRow($this->task);
    partnerDescStatus($this->admin, $this->task, 'done');
    Notification::fake();
    $thrown = $attempt($this->partnerA, $stale, '<p>Example late text</p>');
    expect($thrown)->toBeInstanceOf(ValidationException::class);
    Notification::assertNothingSent();

    // (4) A stale save.
    partnerDescStatus($this->admin, $this->task, 'planned');
    Notification::fake();
    $thrown = $attempt($this->partnerA, partnerDescRow($this->task), '<p>Example partner text</p>', UpdateTaskDescription::fingerprint('<p>Some other text</p>'));
    expect($thrown)->toBeInstanceOf(ValidationException::class);
    Notification::assertNothingSent();

    // (5) An unchanged save.
    Notification::fake();
    $current = partnerDescRow($this->task);
    $thrown = $attempt($this->partnerA, $current, (string) $current->description);
    expect($thrown)->toBeNull()
        ->and(partnerDescEdits($this->task))->toBeEmpty();
    Notification::assertNothingSent();

    // (6) The Admin calling the Action writes the text and tells nobody.
    Notification::fake();
    $thrown = $attempt($this->admin, partnerDescRow($this->task), '<p>Example admin text</p>');
    expect($thrown)->toBeNull()
        ->and(partnerDescRow($this->task)->description)->toBe('<p>Example admin text</p>');
    Notification::assertNothingSent();
});

it('tells the assignee of the same client and never the author or a Partner of another client', function (): void {
    $edit = static fn (User $author, Task $task): Task => partnerDescSave($author, $task, '<p>Edited '.Canary::canary('assignee').'</p>');

    // A Partner assignee of the same client hears of an edit by another Partner, with the Partner link.
    partnerDescAssign($this->admin, $this->task, $this->partnerA2);
    Notification::fake();
    $edit($this->partnerA, $this->task);

    Notification::assertCount(2);
    expect(partnerDescChanges($this->admin))->toHaveCount(1)
        ->and(partnerDescChanges($this->partnerA2))->toHaveCount(1)
        ->and(partnerDescChanges($this->partnerA))->toHaveCount(0)
        ->and(partnerDescChanges($this->partnerB))->toHaveCount(0);

    /** @var TaskChangedNotification $toAdmin */
    $toAdmin = partnerDescChanges($this->admin)->firstOrFail();
    /** @var TaskChangedNotification $toAssignee */
    $toAssignee = partnerDescChanges($this->partnerA2)->firstOrFail();

    expect($toAdmin->recipientIsPartner)->toBeFalse()
        ->and($toAdmin->url)->toEndWith('/admin/tasks/'.$this->task->reference)
        ->and($toAssignee->recipientIsPartner)->toBeTrue()
        ->and($toAssignee->url)->toEndWith('/admin/my-tasks/'.$this->task->reference)
        ->and($toAssignee->changedLabels)->toBe([__('kokpit.tasks.notifications.changed.description', ['actor' => $this->partnerA->name])])
        ->and($toAssignee->event)->toBe(NotificationEvent::AssignmentChange);

    // The assignee editing is the author: only the Admin is told, and never the requester.
    Notification::fake();
    $edit($this->partnerA2, $this->task);

    Notification::assertCount(1);
    expect(partnerDescChanges($this->admin))->toHaveCount(1)
        ->and(partnerDescChanges($this->partnerA2))->toHaveCount(0)
        ->and(partnerDescChanges($this->partnerA))->toHaveCount(0)
        ->and(partnerDescChanges($this->partnerB))->toHaveCount(0);

    // A deactivated assignee gets nothing.
    $this->partnerA2->forceFill(['deactivated_at' => now()])->save();
    Notification::fake();
    $edit($this->partnerA, $this->task);

    Notification::assertCount(1);
    expect(partnerDescChanges($this->admin))->toHaveCount(1)
        ->and(partnerDescChanges($this->partnerA2))->toHaveCount(0)
        ->and(partnerDescChanges($this->partnerB))->toHaveCount(0);

    // The Admin as assignee is told exactly once.
    $this->partnerA2->forceFill(['deactivated_at' => null])->save();
    partnerDescAssign($this->admin, $this->task, $this->admin);
    Notification::fake();
    $edit($this->partnerA, $this->task);

    Notification::assertCount(1);
    expect(partnerDescChanges($this->admin))->toHaveCount(1)
        ->and(partnerDescChanges($this->partnerB))->toHaveCount(0);
});

it('sends no notification when the Admin changes the description on the edit page', function (): void {
    $this->actingAs($this->admin);
    Notification::fake();
    $typed = '<p>Admin '.Canary::canary('editpage').'</p>';

    Livewire::test(EditTask::class, ['record' => $this->task->reference])
        ->fillForm(['description' => $typed])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(partnerDescRow($this->task)->description)->toBe(RichText::clean($typed));
    Notification::assertNothingSent();
});

it('sends no notification when the Admin saves only the description through UpdateTask', function (): void {
    $this->actingAs($this->admin);
    Notification::fake();
    $typed = '<p>Admin '.Canary::canary('updatetask').'</p>';

    partnerDescSystem(fn (): Task => app(UpdateTask::class)->handle($this->admin, Task::query()->findOrFail($this->task->id), ['description' => $typed]));

    expect(partnerDescRow($this->task)->description)->toBe(RichText::clean($typed));
    Notification::assertNothingSent();
});

it('lets the Admin narrow the description edit notice per channel', function (): void {
    $edit = static fn (): Task => partnerDescSave(test()->partnerA, test()->task, '<p>Edited '.Canary::canary('narrow').'</p>');

    // E-mail off: only the bell remains.
    app(UpdateNotificationPreferences::class)->handle($this->admin, $this->admin, ['assignment_change' => ['mail' => false]]);
    Notification::fake();
    $edit();

    Notification::assertSentTo($this->admin, TaskChangedNotification::class, static fn ($n, array $channels): bool => $channels === ['database']);
    Notification::assertCount(1);

    // The bell off, e-mail on: only the e-mail remains.
    app(UpdateNotificationPreferences::class)->handle($this->admin, $this->admin, ['assignment_change' => ['mail' => true, 'database' => false]]);
    Notification::fake();
    $edit();

    Notification::assertSentTo($this->admin, TaskChangedNotification::class, static fn ($n, array $channels): bool => $channels === ['mail']);

    // Both off: nothing is delivered and no other recipient is chosen in the Admin's place.
    app(UpdateNotificationPreferences::class)->handle($this->admin, $this->admin, ['assignment_change' => ['mail' => false, 'database' => false]]);
    Notification::fake();
    $edit();

    Notification::assertNothingSent();
});
