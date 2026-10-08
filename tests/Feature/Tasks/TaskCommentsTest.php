<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
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
