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

/**
 * The payload parts of a Partner comment, assembled at runtime so no single line
 * looks like a payload; each part carries a marker word that must never survive.
 *
 * @return list<string>
 */
function partnerCommentPayloadParts(): array
{
    $lt = '<';

    return [
        $lt.'p>Benign words'.$lt.'/p>',
        $lt.'scr'.'ipt>window.markerOne()'.$lt.'/scr'.'ipt>',
        $lt.'img src="x" on'.'error="markerTwo()">',
        $lt.'a href="java'.'script:markerThree()">Example link text'.$lt.'/a>',
        $lt.'p style="position:'.'fixed;top:markerFour" class="markerFive">More words'.$lt.'/p>',
    ];
}

/**
 * The marker words and substrings that must not appear in stored or rendered text. The
 * style and class markers stand for the attributes themselves, which Filament's own
 * markup uses around the comment.
 *
 * @return list<string>
 */
function partnerCommentForbidden(): array
{
    return ['markerOne', 'markerTwo', 'markerThree', 'markerFour', 'markerFive', '<script', '<img', 'onerror', 'javascript:', 'position:fixed'];
}

/**
 * The HTML of a Livewire component without its snapshot attribute, which carries the raw state.
 */
function partnerCommentHtml(string $html): string
{
    return (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);
}

it('stores and renders a Partner comment without script, handler, script link or style, on both sides', function (): void {
    $this->actingAs($this->partnerA);

    $html = partnerCommentManager($this->task)
        ->callAction(TestAction::make('create')->table(), ['body' => implode('', partnerCommentPayloadParts())])
        ->assertHasNoActionErrors()
        ->html();

    $stored = app(PartnerContext::class)->runAsSystem(fn (): string => $this->task->comments()->firstOrFail()->body);

    foreach (partnerCommentForbidden() as $needle) {
        expect($stored)->not->toContain($needle);
        expect(partnerCommentHtml($html))->not->toContain($needle);
    }

    expect($stored)->toContain('Benign words')->toContain('More words');

    $this->actingAs($this->admin);
    $adminHtml = Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $this->task, 'pageClass' => ViewTask::class])->assertSee('Benign words')->html();

    foreach (partnerCommentForbidden() as $needle) {
        expect(partnerCommentHtml($adminHtml))->not->toContain($needle);
    }
});

it('cleans a Partner comment written around the Action again when it is rendered', function (): void {
    $partner = $this->partnerA;
    app(PartnerContext::class)->runAsSystem(function () use ($partner): void {
        $row = new TaskComment(['body' => implode('', partnerCommentPayloadParts())]);
        $row->forceFill(['task_id' => $this->task->id, 'author_id' => $partner->id])->save();
    });
    $this->actingAs($this->partnerA);

    $html = partnerCommentHtml(partnerCommentManager($this->task)->assertSee('Benign words')->html());

    foreach (partnerCommentForbidden() as $needle) {
        expect($html)->not->toContain($needle);
    }
});

it('sanitises the reason of an escalation like any other Partner comment', function (): void {
    $this->actingAs($this->partnerA);

    Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])
        ->callAction('escalate', ['comment' => implode('', partnerCommentPayloadParts())])
        ->assertHasNoActionErrors();

    $stored = app(PartnerContext::class)->runAsSystem(fn (): string => $this->task->comments()->firstOrFail()->body);

    foreach (partnerCommentForbidden() as $needle) {
        expect($stored)->not->toContain($needle);
    }

    expect($stored)->toContain('Benign words');
});

it('reveals no internal comment of an own task on any Partner surface, in a query or in a count', function (): void {
    $canary = Canary::canary('internal');
    $visible = Canary::canary('visible');
    app(AddTaskComment::class)->handle($this->admin, $this->task, '<p>'.$canary.'</p>', internal: true);
    app(AddTaskComment::class)->handle($this->admin, $this->task, '<p>'.$visible.'</p>');

    // The canary exists, and the Admin sees it on the task page.
    $this->actingAs($this->admin);
    Livewire::test(TaskCommentsRelationManager::class, ['ownerRecord' => $this->task, 'pageClass' => ViewTask::class])->assertSee($canary);
    expect(app(PartnerContext::class)->runAsSystem(static fn (): int => TaskComment::query()->count()))->toBe(2);

    $this->actingAs($this->partnerA);
    $seen = partnerCommentSeen($this->task);

    $page = (string) $this->get('/admin/my-tasks/'.$this->task->reference)->assertOk()->getContent();
    $pageLivewire = Livewire::test(ViewPartnerTask::class, ['record' => $this->task->reference])->html();
    $manager = partnerCommentManager($this->task)->assertSee($visible);
    $managerHtml = $manager->html();
    $records = $manager->instance()->getTable()->getRecords();

    expect($page)->not->toContain($canary)
        ->and($pageLivewire)->not->toContain($canary)
        ->and($managerHtml)->not->toContain($canary)
        ->and($records->count())->toBe(1)
        ->and($records->pluck('body')->implode(''))->not->toContain($canary)
        ->and(TaskComment::query()->count())->toBe(1)
        ->and(TaskComment::query()->where('body', 'like', '%'.$canary.'%')->count())->toBe(0)
        ->and(TaskComment::query()->where('is_internal', true)->count())->toBe(0)
        ->and($seen->comments()->count())->toBe(1)
        ->and($seen->comments)->toHaveCount(1)
        ->and(Task::query()->withCount('comments')->where('reference', $this->task->reference)->firstOrFail()->comments_count)->toBe(1);
});
