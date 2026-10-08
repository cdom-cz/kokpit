<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Text\RichText;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Comments on tasks and subtasks (TA-04, D-08, D-10): the Admin adds rich-text
 * comments, optionally internal; a Partner never reads an internal one. Every
 * name and text is fictional.
 */

/**
 * A client-visible project ABC of a fresh client, written through the domain Action.
 */
function commentProject(?Client $client = null): Project
{
    return app(CreateProject::class)->handle($client ?? Client::factory()->create(), [
        'name' => 'Example comment project',
        'key' => 'ABC',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * The task ABC-1 of a fresh visible project.
 */
function commentTask(User $admin, ?Client $client = null): Task
{
    return app(CreateTask::class)->handle($admin, commentProject($client), ['title' => 'Example comment task']);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('adds a comment from the task page, once visible and once internal, with author, flags and a clean body', function (): void {
    $task = commentTask($this->admin);

    $manager = Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), ['body' => '<p>Example <strong>bold</strong> remark</p>', 'is_internal' => false])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('create')->table(), ['body' => '<p>Example internal remark</p>', 'is_internal' => true])
        ->assertHasNoActionErrors();

    $comments = $task->comments()->get();

    expect($comments)->toHaveCount(2)
        ->and($comments[0]->body)->toBe('<p>Example <strong>bold</strong> remark</p>')
        ->and($comments[0]->is_internal)->toBeFalse()
        ->and($comments[0]->is_escalation)->toBeFalse()
        ->and($comments[0]->author_id)->toBe($this->admin->id)
        ->and($comments[1]->is_internal)->toBeTrue()
        ->and($comments[1]->author_id)->toBe($this->admin->id);

    $manager->assertSee('Example internal remark')
        ->assertSee(__('kokpit.tasks.comments.badges.internal'))
        ->assertSee($this->admin->name);
});

it('lists the comments newest last', function (): void {
    $task = commentTask($this->admin);
    $first = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example first remark</p>');
    $second = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example second remark</p>');

    Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->assertCanSeeTableRecords([$first, $second], inOrder: true);
});

it('cleans the body before it is stored and renders it without markup that could run', function (): void {
    $task = commentTask($this->admin);

    $comment = app(AddTaskComment::class)->handle(
        $this->admin,
        $task,
        '<p onclick="x()">Example <script>alert(1)</script>safe</p><img src="https://example.com/a.png">',
    );

    expect($comment->body)->not->toContain('<script')
        ->and($comment->body)->not->toContain('onclick')
        ->and($comment->body)->not->toContain('<img')
        ->and($comment->body)->toContain('Example');

    // A row written around the Action is still cleaned on output.
    $raw = app(PartnerContext::class)->runAsSystem(static function () use ($task): TaskComment {
        $row = new TaskComment(['body' => '<p>Example raw</p><script>alert(2)</script>']);
        $row->forceFill(['task_id' => $task->id, 'author_id' => User::query()->firstOrFail()->id])->save();

        return $row;
    });

    Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->assertSee('Example raw')
        ->assertDontSeeHtml('<script>alert(2)</script>');

    expect($raw->exists)->toBeTrue();
});

it('comments on a subtask the same way', function (): void {
    $parent = commentTask($this->admin);
    $subtask = app(CreateTask::class)->handle($this->admin, Project::query()->findOrFail($parent->project_id), ['title' => 'Example comment subtask'], $parent);

    Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $subtask, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), ['body' => '<p>Example subtask remark</p>', 'is_internal' => true])
        ->assertHasNoActionErrors();

    expect($subtask->comments()->count())->toBe(1)
        ->and($parent->comments()->count())->toBe(0)
        ->and($subtask->comments()->firstOrFail()->is_internal)->toBeTrue();
});

it('shows a Partner of the own client only the non-internal comment, in the relation and in the count', function (): void {
    $client = Client::factory()->create();
    $task = commentTask($this->admin, $client);

    app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example visible remark</p>');
    app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example secret remark</p>', internal: true);

    expect(TaskComment::query()->count())->toBe(2);

    $this->actingAs(Canary::partnerFor($client->id));

    $seen = Task::query()->where('reference', 'ABC-1')->firstOrFail();

    expect($seen->comments)->toHaveCount(1)
        ->and($seen->comments->first()->body)->toBe('<p>Example visible remark</p>')
        ->and(TaskComment::query()->count())->toBe(1)
        ->and(TaskComment::query()->where('is_internal', true)->count())->toBe(0);
});

it('shows a Partner of another client no comment at all', function (): void {
    $task = commentTask($this->admin);
    app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example visible remark</p>');

    $this->actingAs(Canary::partnerFor(Client::factory()->create()->id));

    expect(TaskComment::query()->count())->toBe(0);
});

it('stores a Partner comment as not internal whatever the payload says', function (): void {
    $client = Client::factory()->create();
    $task = commentTask($this->admin, $client);
    $partner = Canary::partnerFor($client->id);

    $this->actingAs($partner);
    $seen = Task::query()->where('reference', 'ABC-1')->firstOrFail();

    $comment = app(AddTaskComment::class)->handle($partner, $seen, '<p>Example forged remark</p>', internal: true);

    expect($comment->refresh()->is_internal)->toBeFalse()
        ->and($comment->is_escalation)->toBeFalse()
        ->and($comment->author_id)->toBe($partner->id)
        ->and($task->comments()->count())->toBe(1);

    // The comment is visible to the Partner, as a non-internal one must be.
    expect(TaskComment::query()->count())->toBe(1);
});

it('refuses a Partner a comment on a task of another client and stores nothing', function (): void {
    $other = commentTask($this->admin);
    $partner = Canary::partnerFor(Client::factory()->create()->id);

    $this->actingAs($partner);

    expect(fn () => app(AddTaskComment::class)->handle($partner, $other, '<p>Example foreign remark</p>'))
        ->toThrow(AuthorizationException::class);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): int => TaskComment::query()->count()))->toBe(0);
});

it('stores an escalation comment as visible even when it is asked to be internal', function (): void {
    $task = commentTask($this->admin);

    $comment = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example escalation remark</p>', internal: true, escalation: true);

    expect($comment->refresh()->is_internal)->toBeFalse()
        ->and($comment->is_escalation)->toBeTrue();
});

it('refuses a body with no text left as a field error on body and stores nothing', function (string $body): void {
    $task = commentTask($this->admin);

    try {
        app(AddTaskComment::class)->handle($this->admin, $task, $body);
        $this->fail('The empty body was accepted.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['body'])
            ->and($e->errors()['body'][0])->toBe(__('kokpit.tasks.errors.body_empty'));
    }

    expect(TaskComment::query()->count())->toBe(0);
})->with([
    'whitespace only' => ["   \n\t  "],
    'empty editor' => ['<p></p>'],
    'script only' => ['<script>alert(1)</script>'],
]);

it('refuses a body over the length limit as a field error on body', function (): void {
    $task = commentTask($this->admin);

    expect(fn () => app(AddTaskComment::class)->handle($this->admin, $task, '<p>'.str_repeat('a', RichText::MAX_LENGTH).'</p>'))
        ->toThrow(ValidationException::class);

    expect(TaskComment::query()->count())->toBe(0);
});

it('shows the Admin the empty body error under the editor of the modal', function (): void {
    $task = commentTask($this->admin);

    Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('create')->table(), ['body' => '<p></p>'])
        ->assertHasActionErrors(['body']);

    expect(TaskComment::query()->count())->toBe(0);
});

it('denies a Partner to edit or delete a comment and to read an internal one', function (): void {
    $client = Client::factory()->create();
    $task = commentTask($this->admin, $client);
    $visible = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example visible remark</p>');
    $internal = app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example secret remark</p>', internal: true);
    $partner = Canary::partnerFor($client->id);

    expect($partner->can('view', $visible))->toBeTrue()
        ->and($partner->can('create', TaskComment::class))->toBeTrue()
        ->and($partner->can('update', $visible))->toBeFalse()
        ->and($partner->can('delete', $visible))->toBeFalse()
        ->and($partner->can('view', $internal))->toBeFalse()
        ->and($partner->can('update', $internal))->toBeFalse()
        ->and($partner->can('delete', $internal))->toBeFalse();

    // The Admin is admitted by the base policy, but no action offers an edit or a delete.
    expect($this->admin->can('view', $internal))->toBeTrue();
});

it('writes no activity row for a comment, so an internal text never reaches a history', function (): void {
    $task = commentTask($this->admin);
    $before = Activity::query()->count();

    app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example internal history probe</p>', internal: true);

    expect(Activity::query()->count())->toBe($before)
        ->and(Activity::query()->where('properties', 'like', '%history probe%')->count())->toBe(0)
        ->and(Activity::query()->where('subject_type', 'task_comment')->count())->toBe(0);
});

it('offers no edit, delete or bulk action on the comments tab', function (): void {
    $task = commentTask($this->admin);
    app(AddTaskComment::class)->handle($this->admin, $task, '<p>Example append-only remark</p>');

    $table = Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
        ->instance()
        ->getTable();

    expect($table->getRecordActions())->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(array_map(static fn ($action): string => (string) $action->getName(), $table->getHeaderActions()))->toBe(['create']);
});
