<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\ClearEscalation;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\EscalateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Escalation (TA-07, D-06): a Partner escalates an own task with a required
 * reason, the assignee or the Admin clears the flag, and nobody but the Admin
 * ever changes priority or status. Every name and text is fictional.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function escalationSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The task as the signed-in user sees it (through the scoped query).
 */
function escalationSeen(Task $task): Task
{
    return Task::query()->where('reference', $task->reference)->firstOrFail();
}

/**
 * The stored row of the task, read around every scope.
 */
function escalationRow(Task $task): Task
{
    return escalationSystem(static fn (): Task => Task::query()->withTrashed()->findOrFail($task->id));
}

/**
 * The comments of the task, read around every scope.
 *
 * @return list<TaskComment>
 */
function escalationComments(Task $task): array
{
    return escalationSystem(static fn (): array => TaskComment::query()->where('task_id', $task->id)->orderBy('created_at')->get()->all());
}

/**
 * Makes the user the assignee of the task, as the Admin.
 */
function escalationAssign(User $admin, Task $task, User $assignee): Task
{
    return escalationSystem(static fn (): Task => app(UpdateTask::class)->handle($admin, Task::query()->findOrFail($task->id), ['assignee_id' => $assignee->id]));
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partnerA = Canary::partnerFor($this->clientA);
    $this->partnerA2 = Canary::partnerFor($this->clientA);
    $this->partnerB = Canary::partnerFor($this->clientB);

    $this->project = escalationSystem(fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($this->clientA), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
    // Priority and status differ from the defaults, so a write to either would show.
    $this->task = escalationSystem(function (): Task {
        $task = app(CreateTask::class)->handle($this->admin, $this->project, ['title' => Canary::canary('task')]);

        return app(UpdateTask::class)->handle($this->admin, $task, ['priority' => 'high', 'status' => 'in_progress']);
    });
});

it('lets a Partner escalate an own task with a comment and leaves priority and status alone', function (): void {
    $before = escalationRow($this->task);
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('escalate', ['comment' => '<p>Example <strong>urgent</strong> reason</p>'])
        ->assertHasNoActionErrors();

    $after = escalationRow($this->task);
    $comments = escalationComments($this->task);

    expect($after->escalated_at)->not->toBeNull()
        ->and($after->escalated_by_id)->toBe($this->partnerA->id)
        ->and($after->priority)->toBe($before->priority)
        ->and($after->status)->toBe($before->status)
        ->and($after->assignee_id)->toBe($before->assignee_id)
        ->and($comments)->toHaveCount(1)
        ->and($comments[0]->is_escalation)->toBeTrue()
        ->and($comments[0]->is_internal)->toBeFalse()
        ->and($comments[0]->author_id)->toBe($this->partnerA->id)
        ->and($comments[0]->body)->toBe('<p>Example <strong>urgent</strong> reason</p>');
});

it('refuses an empty reason as the field error comment and changes nothing', function (string $reason): void {
    $this->actingAs($this->partnerA);

    try {
        app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), $reason);
        $this->fail('The empty reason was accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['comment']);
    }

    expect(escalationRow($this->task)->escalated_at)->toBeNull()
        ->and(escalationComments($this->task))->toBe([]);
})->with([
    'empty' => [''],
    'whitespace only' => ["  \n\t "],
    'empty editor' => ['<p></p>'],
]);

it('shows the empty reason as an error under the field of the modal', function (): void {
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('escalate', ['comment' => '<p></p>'])
        ->assertHasActionErrors(['comment']);

    expect(escalationRow($this->task)->escalated_at)->toBeNull();
});

it('refuses a second escalation as a field error on the comment and creates no second comment', function (): void {
    $this->actingAs($this->partnerA);
    $stale = escalationSeen($this->task);
    app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), '<p>Example first reason</p>');

    // The stale instance still shows no flag; the row is re-read under the lock.
    expect($stale->escalated_at)->toBeNull();

    try {
        app(EscalateTask::class)->handle($this->partnerA2, $stale, '<p>Example second reason</p>');
        $this->fail('The second escalation was accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['comment'])
            ->and($e->errors()['comment'][0])->toBe(__('kokpit.tasks.errors.already_escalated'));
    }

    $row = escalationRow($this->task);

    expect(escalationComments($this->task))->toHaveCount(1)
        ->and($row->escalated_by_id)->toBe($this->partnerA->id);
});

it('creates no second comment when a modal opened before another session escalated is submitted', function (): void {
    $this->actingAs($this->partnerA);
    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference]);

    // Another session escalates between the page load and the submit; the action is hidden on the next request.
    app(EscalateTask::class)->handle($this->partnerA2, escalationSeen($this->task), '<p>Example first reason</p>');

    $page->callAction('escalate', ['comment' => '<p>Example late reason</p>']);

    $comments = escalationComments($this->task);

    expect($comments)->toHaveCount(1)
        ->and($comments[0]->author_id)->toBe($this->partnerA2->id)
        ->and(escalationRow($this->task)->escalated_by_id)->toBe($this->partnerA2->id);
});

it('hides the escalate action on the Partner page while the task is escalated', function (): void {
    $this->actingAs($this->partnerA);
    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->assertActionVisible('escalate');

    $page->callAction('escalate', ['comment' => '<p>Example reason</p>']);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->assertActionHidden('escalate');
});

it('keeps a Partner of another client out of the escalation', function (): void {
    $this->actingAs($this->partnerB);

    $this->get('/admin/my-tasks/'.$this->task->reference)->assertNotFound();

    $loaded = escalationRow($this->task);

    expect(fn () => app(EscalateTask::class)->handle($this->partnerB, $loaded, '<p>Example foreign reason</p>'))
        ->toThrow(AuthorizationException::class);

    expect(escalationRow($this->task)->escalated_at)->toBeNull()
        ->and(escalationComments($this->task))->toBe([]);
});

it('shows the Admin the marker with the Partner and the time, clears it, and lets the Partner escalate again', function (): void {
    $this->actingAs($this->partnerA);
    app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), '<p>Example first reason</p>');

    $this->actingAs($this->admin);

    $page = Livewire::test(ViewTask::class, ['record' => $this->task->reference])
        ->assertSee(__('kokpit.tasks.escalation.label'))
        ->assertSee($this->partnerA->name)
        ->assertActionVisible('clearEscalation');

    $page->callAction('clearEscalation')->assertHasNoActionErrors();

    $row = escalationRow($this->task);

    expect($row->escalated_at)->toBeNull()
        ->and($row->escalated_by_id)->toBeNull()
        ->and($row->priority)->toBe($this->task->priority)
        ->and($row->status)->toBe($this->task->status);

    Livewire::test(ViewTask::class, ['record' => $this->task->reference])->assertActionHidden('clearEscalation');

    $this->actingAs($this->partnerA);
    app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), '<p>Example second reason</p>');

    expect(escalationRow($this->task)->escalated_at)->not->toBeNull()
        ->and(escalationComments($this->task))->toHaveCount(2);
});

it('shows the Admin no marker and no clear action while the task is not escalated', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ViewTask::class, ['record' => $this->task->reference])
        ->assertDontSee(__('kokpit.tasks.escalation.label'))
        ->assertActionHidden('clearEscalation');
});

it('lets the Partner who is the assignee clear the flag from the own task page and nothing else changes', function (): void {
    $this->actingAs($this->partnerA);
    app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), '<p>Example reason</p>');
    escalationAssign($this->admin, $this->task, $this->partnerA2);
    $before = escalationRow($this->task);

    $this->actingAs($this->partnerA2);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->assertActionVisible('clearEscalation')
        ->callAction('clearEscalation')
        ->assertHasNoActionErrors();

    $after = escalationRow($this->task);

    expect($after->escalated_at)->toBeNull()
        ->and($after->escalated_by_id)->toBeNull()
        ->and($after->priority)->toBe($before->priority)
        ->and($after->status)->toBe($before->status)
        ->and($after->assignee_id)->toBe($this->partnerA2->id)
        ->and($after->title)->toBe($before->title);
});

it('does not let the escalating Partner clear the flag when the Partner is not the assignee', function (): void {
    $this->actingAs($this->partnerA);
    app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), '<p>Example reason</p>');

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->assertActionHidden('clearEscalation');

    expect(fn () => app(ClearEscalation::class)->handle($this->partnerA, escalationSeen($this->task)))
        ->toThrow(AuthorizationException::class);

    expect(escalationRow($this->task)->escalated_at)->not->toBeNull();
});

it('does not let a Partner of another client clear a flag', function (): void {
    $this->actingAs($this->partnerA);
    app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), '<p>Example reason</p>');

    $this->actingAs($this->partnerB);
    $loaded = escalationRow($this->task);

    expect(fn () => app(ClearEscalation::class)->handle($this->partnerB, $loaded))
        ->toThrow(ModelNotFoundException::class);

    expect(escalationRow($this->task)->escalated_at)->not->toBeNull();
});

it('refuses a Partner who was reassigned away after loading the page, checking the right on the locked row', function (): void {
    $this->actingAs($this->partnerA);
    app(EscalateTask::class)->handle($this->partnerA, escalationSeen($this->task), '<p>Example reason</p>');
    escalationAssign($this->admin, $this->task, $this->partnerA2);

    $this->actingAs($this->partnerA2);
    $stale = escalationSeen($this->task);

    expect($stale->assignee_id)->toBe($this->partnerA2->id);

    // The Admin takes the task back after the page was loaded.
    escalationAssign($this->admin, $this->task, $this->admin);

    expect(fn () => app(ClearEscalation::class)->handle($this->partnerA2, $stale))
        ->toThrow(AuthorizationException::class);

    expect(escalationRow($this->task)->escalated_at)->not->toBeNull();
});

it('keeps priority and status with the Admin even for a Partner who is the assignee', function (): void {
    escalationAssign($this->admin, $this->task, $this->partnerA2);
    $before = escalationRow($this->task);
    $this->actingAs($this->partnerA2);

    expect(fn () => app(UpdateTask::class)->handle($this->partnerA2, escalationSeen($this->task), ['priority' => 'urgent', 'status' => 'done']))
        ->toThrow(AuthorizationException::class);

    $after = escalationRow($this->task);

    expect($after->priority)->toBe($before->priority)
        ->and($after->status)->toBe($before->status);

    $page = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference]);
    $names = array_map(static fn ($action): string => (string) $action->getName(), array_values($page->instance()->getCachedHeaderActions()));

    expect($names)->toBe(['editDescription', 'escalate', 'clearEscalation']);
});

it('refuses to clear a task that is not escalated as a field error on task', function (): void {
    $this->actingAs($this->admin);

    try {
        app(ClearEscalation::class)->handle($this->admin, escalationSeen($this->task));
        $this->fail('Clearing an unflagged task was accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['task'])
            ->and($e->errors()['task'][0])->toBe(__('kokpit.tasks.errors.not_escalated'));
    }
});
