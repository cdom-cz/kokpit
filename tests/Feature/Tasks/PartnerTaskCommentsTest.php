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
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Partner comments on an own task (TA-07, TA-04, D-08, D-10). Every name and text
 * is fictional; dangerous fragments are assembled at runtime.
 */

/**
 * A client-visible project of the given client, written through the domain Action.
 */
function partnerCommentProject(string $clientId): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]));
}

/**
 * A task of the project raised by the Admin, written in a system run.
 */
function partnerCommentTask(User $admin, Project $project): Task
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): Task => app(CreateTask::class)->handle($admin, $project, ['title' => Canary::canary('task')]),
    );
}

/**
 * The task as the signed-in Partner sees it (through the scoped query).
 */
function partnerCommentSeen(Task $task): Task
{
    return Task::query()->where('reference', $task->reference)->firstOrFail();
}

/**
 * The Partner comments relation manager mounted on the task page of the Partner.
 */
function partnerCommentManager(Task $task): Testable
{
    return Livewire::test(PartnerTaskCommentsRelationManager::class, ['ownerRecord' => partnerCommentSeen($task), 'pageClass' => ViewPartnerTask::class]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->projectA = partnerCommentProject($this->clientA);
    $this->partnerA = Canary::partnerFor($this->clientA);
    $this->task = partnerCommentTask($this->admin, $this->projectA);
});

it('shows a Partner the non-internal comment of an own task and not the internal one', function (): void {
    $visible = app(AddTaskComment::class)->handle($this->admin, $this->task, '<p>'.Canary::canary('visible').'</p>');
    $internal = app(AddTaskComment::class)->handle($this->admin, $this->task, '<p>'.Canary::canary('internal').'</p>', internal: true);
    $this->actingAs($this->partnerA);

    partnerCommentManager($this->task)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$internal])
        ->assertSee(strip_tags($visible->body))
        ->assertDontSee(strip_tags($internal->body));
});

it('registers the comments tab on the Partner task page', function (): void {
    expect(PartnerTaskResource::getRelations())->toBe([PartnerTaskCommentsRelationManager::class]);

    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->assertSeeLivewire(PartnerTaskCommentsRelationManager::class);
});

it('lets a Partner add a rich-text comment that is stored for the Partner as a public comment', function (): void {
    $this->actingAs($this->partnerA);

    partnerCommentManager($this->task)
        ->callAction(TestAction::make('create')->table(), ['body' => '<p>Example <strong>bold</strong> remark</p>'])
        ->assertHasNoActionErrors();

    $comment = app(PartnerContext::class)->runAsSystem(fn (): TaskComment => $this->task->comments()->firstOrFail());

    expect($comment->body)->toBe('<p>Example <strong>bold</strong> remark</p>')
        ->and($comment->author_id)->toBe($this->partnerA->id)
        ->and($comment->is_internal)->toBeFalse()
        ->and($comment->is_escalation)->toBeFalse();
});

it('shows the Partner comment to the Admin in the comments tab of the task page', function (): void {
    $this->actingAs($this->partnerA);
    partnerCommentManager($this->task)
        ->callAction(TestAction::make('create')->table(), ['body' => '<p>Example partner remark</p>']);

    $this->actingAs($this->admin);

    Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $this->task, 'pageClass' => ViewTask::class])
        ->assertSee('Example partner remark')
        ->assertSee($this->partnerA->name);
});

it('stores a forged internal flag in the create data as not internal', function (): void {
    $this->actingAs($this->partnerA);

    partnerCommentManager($this->task)
        ->callAction(TestAction::make('create')->table(), ['body' => '<p>Example forged remark</p>', 'is_internal' => true, 'is_escalation' => true])
        ->assertHasNoActionErrors();

    $comment = app(PartnerContext::class)->runAsSystem(fn (): TaskComment => $this->task->comments()->firstOrFail());

    expect($comment->is_internal)->toBeFalse()
        ->and($comment->is_escalation)->toBeFalse();
});

it('offers a Partner no internal column, toggle, edit, delete or bulk action', function (): void {
    app(AddTaskComment::class)->handle($this->admin, $this->task, '<p>Example append-only remark</p>');
    $this->actingAs($this->partnerA);

    $manager = partnerCommentManager($this->task);
    $table = $manager->instance()->getTable();

    expect(array_keys($table->getColumns()))->toBe(['author.name', 'created_at', 'body', 'is_escalation'])
        ->and($table->getRecordActions())->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(array_map(static fn ($action): string => (string) $action->getName(), $table->getHeaderActions()))->toBe(['create']);

    $schema = $manager->instance()->form(Schema::make($manager->instance()));
    $fields = array_map(static fn ($component): ?string => method_exists($component, 'getName') ? $component->getName() : null, $schema->getComponents());

    expect($fields)->toBe(['body']);
});

it('refuses the comments tab of a task of another client', function (): void {
    $projectB = partnerCommentProject($this->clientB);
    $foreign = partnerCommentTask($this->admin, $projectB);
    $this->actingAs($this->partnerA);

    expect(fn () => partnerCommentSeen($foreign))->toThrow(ModelNotFoundException::class);
});
