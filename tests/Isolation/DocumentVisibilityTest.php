<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Partner\Resources\PartnerProjectResource;
use App\Filament\Partner\Resources\PartnerProjectResource\Pages\ViewPartnerProject;
use App\Filament\Partner\Resources\PartnerProjectResource\RelationManagers\PartnerProjectDocumentsRelationManager;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskDocumentsRelationManager;
use App\Filament\Support\DocumentColumns;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * What a Partner meets of files (DO-01, D-03, D-04, D-20, D-21): only files flagged visible that a project or
 * task of the own client owns, never a hidden file, an internal-comment file, a client-owned or unattached
 * file, nor anything of another client or an archived owner. Every name and canary is fictional and
 * assembled at runtime.
 */

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function documentVisibilitySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client-visible project of the client, written through the domain Action.
 *
 * @param  array<string, mixed>  $attributes
 */
function documentVisibilityProject(string $clientId, array $attributes = []): Project
{
    return documentVisibilitySystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
        ...$attributes,
    ]));
}

/**
 * Attaches one fictional file to the owner as the Admin and returns it.
 */
function documentVisibilityFile(User $admin, ?object $owner, string $name, ?bool $visible = null): Document
{
    return documentVisibilitySystem(static fn (): Document => app(AttachDocuments::class)
        ->handle($admin, $owner, [DocumentFixtures::upload($name)], visibleToClient: $visible)
        ->firstOrFail());
}

/**
 * The Partner project tab mounted on the project page, with the project read through the scoped query.
 */
function documentVisibilityProjectTab(Project $project): Testable
{
    $seen = Project::query()->whereKey($project->getKey())->firstOrFail();

    return Livewire::test(PartnerProjectDocumentsRelationManager::class, ['ownerRecord' => $seen, 'pageClass' => ViewPartnerProject::class]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->partnerA = Canary::partnerFor($this->clientA);
    $this->projectA = documentVisibilityProject($this->clientA);
});

describe('project tab (tracer)', function (): void {
    it('registers the Soubory tab on the Partner project page', function (): void {
        expect(PartnerProjectResource::getRelations())->toBe([PartnerProjectDocumentsRelationManager::class]);

        $this->actingAs($this->partnerA);

        Livewire::test(ViewPartnerProject::class, ['record' => $this->projectA->getKey()])
            ->assertSeeLivewire(PartnerProjectDocumentsRelationManager::class);
    });

    it('lists the shared file of an own project and not its hidden twin', function (): void {
        $shared = documentVisibilityFile($this->admin, $this->projectA, 'shared-example.txt', true);
        $hidden = documentVisibilityFile($this->admin, $this->projectA, 'hidden-example.txt', false);
        $this->actingAs($this->partnerA);

        documentVisibilityProjectTab($this->projectA)
            ->assertCanSeeTableRecords([$shared])
            ->assertCanNotSeeTableRecords([$hidden])
            ->assertSee('shared-example.txt')
            ->assertDontSee('hidden-example.txt');
    });

    it('redirects a download click of the Partner to a signed link bound to the Partner', function (): void {
        $shared = documentVisibilityFile($this->admin, $this->projectA, 'shared-example.txt', true);
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityProjectTab($this->projectA)
            ->callAction(TestAction::make('download')->table($shared));

        $url = data_get($tab->effects, 'redirect');

        expect($url)->toBeString()
            ->and($url)->toContain(route('filament.admin.files.document', ['document' => $shared->getKey()], false))
            ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query['u'])->toBe($this->partnerA->getKey());
    });

    it('streams the shared file to the Partner through the signed link', function (): void {
        $shared = documentVisibilityFile($this->admin, $this->projectA, 'shared-example.txt', true);
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityProjectTab($this->projectA)
            ->callAction(TestAction::make('download')->table($shared));

        $this->get((string) data_get($tab->effects, 'redirect'))->assertOk();
    });
});

/**
 * The Partner task tab mounted on the task page, with the task read through the scoped query.
 */
function documentVisibilityTaskTab(Task $task): Testable
{
    $seen = Task::query()->whereKey($task->getKey())->firstOrFail();

    return Livewire::test(PartnerTaskDocumentsRelationManager::class, ['ownerRecord' => $seen, 'pageClass' => ViewPartnerTask::class]);
}

/**
 * Attaches a file and, where asked, flips the flag by hand the way only a system write could (the Action refuses
 * to share an internal-comment file, so a row like this exists only through a bug or a direct write).
 */
function documentVisibilityForceVisible(Document $document): Document
{
    documentVisibilitySystem(static fn (): bool => $document->forceFill(['visible_to_client' => true])->save());

    return $document->refresh();
}

/**
 * Builds the world of the matrix as the Admin: thirteen kinds of file on client A, a fourteenth that is the
 * hidden twin of a task file, and one visible file of client B. Archived owners are archived after their files
 * were attached, the way the application does it.
 *
 * Kind names are the keys of `kinds`; `owners` lists the owner rows the relation check reads.
 *
 * @return array{kinds: array<string, Document>, owners: list<array{class: class-string<Project|Task|TaskComment>, id: string}>, project: Project, task: Task, archivedProject: Project, archivedTask: Task, client: Client}
 */
function documentVisibilityWorld(User $admin, string $clientA, string $clientB): array
{
    $file = static fn (?object $owner, string $name, ?bool $visible = null): Document => documentVisibilityFile($admin, $owner, $name, $visible);
    $createTask = static fn (Project $project, string $title, ?Task $parent = null): Task => documentVisibilitySystem(
        static fn (): Task => app(CreateTask::class)->handle($admin, $project, ['title' => $title], $parent),
    );
    $comment = static fn (Task $task, bool $internal): TaskComment => documentVisibilitySystem(
        static fn (): TaskComment => app(AddTaskComment::class)->handle($admin, $task, '<p>'.Canary::canary('comment').'</p>', internal: $internal),
    );

    $project = documentVisibilityProject($clientA);
    $notVisibleProject = documentVisibilityProject($clientA, ['client_visible' => false]);
    $archivedProject = documentVisibilityProject($clientA);
    $projectB = documentVisibilityProject($clientB);

    $task = $createTask($project, Canary::canary('task'));
    $subtask = $createTask($project, Canary::canary('subtask'), $task);
    $archivedTask = $createTask($project, Canary::canary('archived-task'));
    $archivedProjectTask = $createTask($archivedProject, Canary::canary('archived-project-task'));
    $publicComment = $comment($task, internal: false);
    $internalComment = $comment($task, internal: true);
    $archivedTaskComment = $comment($archivedTask, internal: false);

    $kinds = [
        'project_visible' => $file($project, 'project-visible.txt', true),
        'project_hidden' => $file($project, 'project-hidden.txt', false),
        'task_visible' => $file($task, 'task-visible.txt', true),
        'task_hidden' => $file($task, 'task-hidden.txt', false),
        'subtask_visible' => $file($subtask, 'subtask-visible.txt', true),
        'comment_visible' => $file($publicComment, 'comment-visible.txt'),
        'internal_comment_visible' => documentVisibilityForceVisible($file($internalComment, 'internal-comment.txt')),
        'client_owned' => $file(documentVisibilitySystem(static fn (): Client => Client::query()->findOrFail($clientA)), 'client-owned.txt'),
        'unattached' => $file(null, 'unattached.txt'),
        'project_not_client_visible' => $file($notVisibleProject, 'not-client-visible-project.txt', true),
        'archived_project_visible' => $file($archivedProject, 'archived-project.txt', true),
        'archived_project_task_visible' => $file($archivedProjectTask, 'archived-project-task.txt', true),
        'archived_task_visible' => $file($archivedTask, 'archived-task.txt', true),
        'archived_task_comment_visible' => $file($archivedTaskComment, 'archived-task-comment.txt'),
        'other_client_project_visible' => $file($projectB, 'other-client-project.txt', true),
    ];

    documentVisibilitySystem(static function () use ($admin, $archivedProject, $archivedTask): void {
        $archivedProject->delete();
        app(ArchiveTask::class)->handle($admin, $archivedTask);
    });

    return [
        'kinds' => $kinds,
        'owners' => [
            ['class' => Project::class, 'id' => (string) $project->getKey()],
            ['class' => Project::class, 'id' => (string) $notVisibleProject->getKey()],
            ['class' => Project::class, 'id' => (string) $archivedProject->getKey()],
            ['class' => Project::class, 'id' => (string) $projectB->getKey()],
            ['class' => Task::class, 'id' => (string) $task->getKey()],
            ['class' => Task::class, 'id' => (string) $subtask->getKey()],
            ['class' => Task::class, 'id' => (string) $archivedTask->getKey()],
            ['class' => Task::class, 'id' => (string) $archivedProjectTask->getKey()],
            ['class' => TaskComment::class, 'id' => (string) $publicComment->getKey()],
            ['class' => TaskComment::class, 'id' => (string) $internalComment->getKey()],
            ['class' => TaskComment::class, 'id' => (string) $archivedTaskComment->getKey()],
        ],
        'project' => $project,
        'task' => $task,
        'archivedProject' => $archivedProject,
        'archivedTask' => $archivedTask,
        'client' => documentVisibilitySystem(static fn (): Client => Client::query()->findOrFail($clientA)),
    ];
}

/**
 * The kinds of file a viewer may meet in a phase of the matrix.
 *
 * Phases: `initial` (archived project and task still archived), `restored` (both restored; also the state after
 * the client was archived and restored again) and `client_archived` (client A archived). Partner A reads the
 * files flagged visible that a client-visible project or its tasks and non-internal comments own; Partner B
 * reads the one file of client B; a Partner without a client reads nothing.
 *
 * @return list<string>
 */
function documentVisibilityExpected(string $viewer, string $phase): array
{
    $initial = ['project_visible', 'task_visible', 'subtask_visible', 'comment_visible'];
    $restored = [...$initial, 'archived_project_visible', 'archived_project_task_visible', 'archived_task_visible', 'archived_task_comment_visible'];

    return match ($viewer) {
        'a' => match ($phase) {
            'initial' => $initial,
            'restored' => $restored,
            default => [],
        },
        'b' => ['other_client_project_visible'],
        default => [],
    };
}

/**
 * Compares what a provider says each viewer may see with the expectation of the phase and names every
 * difference as "<viewer> sees|misses <kind>". An empty list means the provider obeys the matrix.
 *
 * @param  array<string, Document>  $kinds
 * @param  Closure(string): list<string>  $provider  the document ids a viewer ('a', 'b', 'none') can see
 * @return list<string>
 */
function documentVisibilityViolations(array $kinds, string $phase, Closure $provider): array
{
    $kindById = [];

    foreach ($kinds as $kind => $document) {
        $kindById[(string) $document->getKey()] = $kind;
    }

    $violations = [];

    foreach (['a', 'b', 'none'] as $viewer) {
        $got = $provider($viewer);
        $want = array_map(static fn (string $kind): string => (string) $kinds[$kind]->getKey(), documentVisibilityExpected($viewer, $phase));

        foreach (array_diff($got, $want) as $id) {
            $violations[] = "{$viewer} sees ".($kindById[$id] ?? $id);
        }

        foreach (array_diff($want, $got) as $id) {
            $violations[] = "{$viewer} misses ".($kindById[$id] ?? $id);
        }
    }

    sort($violations, SORT_STRING);

    return $violations;
}

/**
 * The ids a signed-in viewer gets from the scoped Document model.
 *
 * @return list<string>
 */
function documentVisibilityScopedIds(): array
{
    /** @var list<string> $ids */
    $ids = Document::query()->pluck('id')->all();
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * Asserts that every way a viewer could meet a file gives the same answer: the list, the count, an exists() check
 * by id, the relations of every owner row, the policy and the signed file route.
 *
 * @param  array{kinds: array<string, Document>, owners: list<array{class: class-string<Project|Task|TaskComment>, id: string}>}  $world
 */
function documentVisibilityAssertEveryPath(object $test, array $world, User $viewer, string $viewerKey, string $phase): void
{
    $test->actingAs($viewer);

    $want = array_map(static fn (string $kind): string => (string) $world['kinds'][$kind]->getKey(), documentVisibilityExpected($viewerKey, $phase));
    sort($want, SORT_STRING);

    expect(documentVisibilityScopedIds())->toBe($want, "list, viewer {$viewerKey}, phase {$phase}")
        ->and(Document::query()->count())->toBe(count($want), "count, viewer {$viewerKey}, phase {$phase}");

    $viaRelations = [];

    foreach ($world['owners'] as $owner) {
        $row = $owner['class']::query()->whereKey($owner['id'])->first();

        if ($row !== null) {
            $viaRelations = [...$viaRelations, ...$row->documents()->pluck('id')->all()];
        }
    }

    sort($viaRelations, SORT_STRING);

    expect($viaRelations)->toBe($want, "relations, viewer {$viewerKey}, phase {$phase}");

    $strictNotFound = $viewerKey !== 'none' && $phase !== 'client_archived';

    foreach ($world['kinds'] as $kind => $document) {
        $visible = in_array((string) $document->getKey(), $want, true);

        expect(Document::query()->whereKey($document->getKey())->exists())->toBe($visible, "exists, {$kind}, viewer {$viewerKey}, phase {$phase}")
            ->and(Gate::forUser($viewer)->allows('view', $document))->toBe($visible, "policy, {$kind}, viewer {$viewerKey}, phase {$phase}");

        $status = $test->get(DocumentUrl::download($document, $viewer))->getStatusCode();

        if ($visible) {
            expect($status)->toBe(200, "route, {$kind}, viewer {$viewerKey}, phase {$phase}");
        } elseif ($strictNotFound) {
            expect($status)->toBe(404, "route, {$kind}, viewer {$viewerKey}, phase {$phase}");
        } else {
            expect($status)->toBeIn([403, 404], "route, {$kind}, viewer {$viewerKey}, phase {$phase}");
        }
    }
}

describe('task tab', function (): void {
    it('registers the Soubory tab after the comments tab on the Partner task page', function (): void {
        expect(PartnerTaskResource::getRelations())->toBe([PartnerTaskCommentsRelationManager::class, PartnerTaskDocumentsRelationManager::class]);

        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $this->actingAs($this->partnerA);

        $page = Livewire::test(ViewPartnerTask::class, ['record' => $world['task']->reference])->instance();

        expect(array_values($page->getRelationManagers()))->toBe([PartnerTaskCommentsRelationManager::class, PartnerTaskDocumentsRelationManager::class]);
    });

    it('lists the visible task file and none of its twins, with exactly the pinned Partner columns', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $kinds = $world['kinds'];
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityTaskTab($world['task'])
            ->assertCanSeeTableRecords([$kinds['task_visible']])
            ->assertCanNotSeeTableRecords([$kinds['task_hidden'], $kinds['subtask_visible'], $kinds['comment_visible'], $kinds['internal_comment_visible']])
            ->assertSee('task-visible.txt')
            ->assertDontSee('task-hidden.txt')
            ->assertDontSee('internal-comment.txt');

        expect(array_keys($tab->instance()->getTable()->getColumns()))->toBe(DocumentColumns::PARTNER_COLUMN_NAMES);
    });

    it('shows a subtask tab only its own file', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $subtask = documentVisibilitySystem(static fn (): Task => Task::query()->where('title', 'like', 'CANARY_subtask%')->firstOrFail());
        $this->actingAs($this->partnerA);

        documentVisibilityTaskTab($subtask)
            ->assertCanSeeTableRecords([$world['kinds']['subtask_visible']])
            ->assertCanNotSeeTableRecords([$world['kinds']['task_visible']]);
    });

    it('shows the project tab exactly the pinned Partner columns and no uploader, owner kind or flag', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityProjectTab($world['project'])
            ->assertCanSeeTableRecords([$world['kinds']['project_visible']])
            ->assertCanNotSeeTableRecords([$world['kinds']['project_hidden'], $world['kinds']['task_visible']])
            ->assertDontSee($this->admin->name)
            ->assertDontSee((string) __('documents.columns.visible_to_client'))
            ->assertDontSee((string) __('documents.columns.uploaded_by'))
            ->assertDontSee((string) __('documents.columns.owner'));

        expect(array_keys($tab->instance()->getTable()->getColumns()))->toBe(DocumentColumns::PARTNER_COLUMN_NAMES)
            ->and($tab->instance()->getTable()->getHeaderActions())->toBe([])
            ->and($tab->instance()->getTable()->getFlatBulkActions())->toBe([]);

        foreach (['upload', 'rename', 'toggleVisibility', 'delete'] as $action) {
            expect($tab->instance()->getTable()->getAction($action))->toBeNull("{$action} must not be offered to a Partner");
        }
    });

    it('names no uploader, owner, flag, client or internal key in the pinned Partner column list', function (): void {
        foreach (DocumentColumns::PARTNER_COLUMN_NAMES as $name) {
            expect(preg_match('/uploader|owner|visible|client|internal|account|user/i', $name))->toBe(0, "{$name} must not be on a Partner surface");
        }
    });

    it('gives a Partner without a client no page, no tab content and no file', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $this->actingAs(Canary::partnerFor(null));

        expect(PartnerProjectResource::canAccess())->toBeFalse()
            ->and(PartnerTaskResource::canAccess())->toBeFalse()
            ->and(documentVisibilityScopedIds())->toBe([]);

        // Even with the owner row handed over, the tab is refused.
        Livewire::test(PartnerProjectDocumentsRelationManager::class, ['ownerRecord' => $world['project'], 'pageClass' => ViewPartnerProject::class])
            ->assertForbidden();
    });

    it('does not let a Partner of another client open the tab of a foreign task or project', function (): void {
        documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $partnerB = Canary::partnerFor($this->clientB);
        $this->actingAs($partnerB);

        expect(Project::query()->whereKey($this->projectA->getKey())->first())->toBeNull()
            ->and(Task::query()->where('title', 'like', 'CANARY_task%')->first())->toBeNull();
    });
});

describe('matrix', function (): void {
    it('gives every viewer the same answer on every path, before and after the archived owners are restored', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $viewers = ['a' => $this->partnerA, 'b' => Canary::partnerFor($this->clientB), 'none' => Canary::partnerFor(null)];

        foreach ($viewers as $key => $viewer) {
            documentVisibilityAssertEveryPath($this, $world, $viewer, $key, 'initial');
        }

        // D-21: restoring the archived owners brings their shared files back, the hidden kinds stay hidden.
        $this->actingAs($this->admin);
        documentVisibilitySystem(function () use ($world): void {
            $world['archivedProject']->restore();
            app(RestoreTask::class)->handle($this->admin, $world['archivedTask']);
        });

        foreach ($viewers as $key => $viewer) {
            documentVisibilityAssertEveryPath($this, $world, $viewer, $key, 'restored');
        }
    });

    it('hides everything of client A while it is archived and brings the shared files back on restore', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $viewers = ['a' => $this->partnerA, 'b' => Canary::partnerFor($this->clientB), 'none' => Canary::partnerFor(null)];

        $this->actingAs($this->admin);
        documentVisibilitySystem(function () use ($world): void {
            $world['archivedProject']->restore();
            app(RestoreTask::class)->handle($this->admin, $world['archivedTask']);
            app(ArchiveClient::class)->handle($world['client']);
        });

        foreach ($viewers as $key => $viewer) {
            documentVisibilityAssertEveryPath($this, $world, $viewer, $key, 'client_archived');
        }

        $this->actingAs($this->admin);
        documentVisibilitySystem(fn () => app(RestoreClient::class)->handle($world['client']->refresh()));

        foreach ($viewers as $key => $viewer) {
            documentVisibilityAssertEveryPath($this, $world, $viewer, $key, 'restored');
        }
    });

    it('lets the Admin see all fourteen files of client A and the file of client B', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $this->actingAs($this->admin);

        $adminIds = documentVisibilityScopedIds();
        $all = array_map(static fn (Document $document): string => (string) $document->getKey(), array_values($world['kinds']));
        sort($all, SORT_STRING);

        expect($adminIds)->toBe($all)
            ->and(count($world['kinds']))->toBe(15);

        foreach ($world['kinds'] as $kind => $document) {
            expect(Gate::forUser($this->admin)->allows('view', $document))->toBeTrue("admin policy, {$kind}")
                ->and($this->get(DocumentUrl::download($document, $this->admin))->getStatusCode())->toBe(200, "admin route, {$kind}");
        }
    });

    it('reports no violation for the real Document scope in every phase it can be asked', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $viewers = ['a' => $this->partnerA, 'b' => Canary::partnerFor($this->clientB), 'none' => Canary::partnerFor(null)];

        $real = function (string $viewer) use ($viewers): array {
            $this->actingAs($viewers[$viewer]);

            return documentVisibilityScopedIds();
        };

        expect(documentVisibilityViolations($world['kinds'], 'initial', $real))->toBe([]);
    });

    it('answers 403 when the policy is asked about a Partner of another client for a shared file', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $partnerB = Canary::partnerFor($this->clientB);

        expect(fn () => Gate::forUser($partnerB)->authorize('view', $world['kinds']['project_visible']))->toThrow(AuthorizationException::class);
    });
});

/**
 * The ids a hand-built provider returns for the client of a viewer: the scope written out as plain queries that
 * run as the Admin with a fixed client filter. With both switches on it is the rule of Document::constrainForPartner()
 * and must agree with the real scope; each switch removes one rule, which is the mutation the self-check applies.
 *
 * @param  bool  $flagRule  keep the `visible_to_client` condition (the leaky provider drops it)
 * @param  bool  $internalRule  keep the delegation to the non-internal comments (the leaky provider takes every comment)
 * @return list<string>
 */
function documentVisibilityHandBuiltIds(?string $clientId, bool $flagRule, bool $internalRule): array
{
    if ($clientId === null) {
        return [];
    }

    $projects = Project::query()->where('client_id', $clientId)->where('client_visible', true)->select('projects.id');
    $tasks = Task::query()->whereIn('project_id', $projects)->select('tasks.id');
    $comments = TaskComment::query()->whereIn('task_id', $tasks)->when($internalRule, static fn ($query) => $query->where('is_internal', false))->select('task_comments.id');

    $query = Document::query()
        ->where('client_id', $clientId)
        ->where(static fn ($owners) => $owners
            ->where(static fn ($project) => $project->where('owner_type', 'project')->whereIn('owner_id', $projects))
            ->orWhere(static fn ($task) => $task->where('owner_type', 'task')->whereIn('owner_id', $tasks))
            ->orWhere(static fn ($comment) => $comment->where('owner_type', 'task_comment')->whereIn('owner_id', $comments)))
        ->when($flagRule, static fn ($files) => $files->where('visible_to_client', true));

    /** @var list<string> $ids */
    $ids = $query->pluck('id')->all();
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * A provider over the hand-built query, run as the Admin, for the viewers 'a', 'b' and 'none'.
 *
 * @return Closure(string): list<string>
 */
function documentVisibilityHandBuiltProvider(object $test, User $admin, string $clientA, string $clientB, bool $flagRule, bool $internalRule): Closure
{
    return static function (string $viewer) use ($test, $admin, $clientA, $clientB, $flagRule, $internalRule): array {
        $test->actingAs($admin);

        return documentVisibilityHandBuiltIds(match ($viewer) {
            'a' => $clientA,
            'b' => $clientB,
            default => null,
        }, $flagRule, $internalRule);
    };
}

/**
 * Attaches a fictional file with the given bytes to the owner as the Admin, with the given visibility.
 */
function documentVisibilityBinaryFile(User $admin, object $owner, string $name, string $content, bool $visible): Document
{
    return documentVisibilitySystem(static fn (): Document => app(AttachDocuments::class)
        ->handle($admin, $owner, [DocumentFixtures::upload($name, $content)], visibleToClient: $visible)
        ->firstOrFail());
}

describe('preview of a file (lightbox and new tab)', function (): void {
    it('gives a visible PDF a new-tab link bound to the Partner and the hidden twin no row and no link', function (): void {
        $this->freezeTime();

        $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $shared = documentVisibilityBinaryFile($this->admin, $this->projectA, 'shared-paper-example.pdf', $pdfBytes, true);
        $hidden = documentVisibilityBinaryFile($this->admin, $this->projectA, 'hidden-paper-example.pdf', $pdfBytes, false);
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityProjectTab($this->projectA);

        $tab->assertActionExists(TestAction::make('preview')->table($shared), function (Action $action): bool {
            $url = (string) $action->getUrl();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $action->shouldOpenUrlInNewTab()
                && ($query['u'] ?? null) === $this->partnerA->getKey()
                && ($query['mode'] ?? null) === 'inline'
                && URL::hasValidSignature(Request::create($url));
        });

        $html = $tab->html();

        expect(substr_count($html, 'signature='))->toBe(1)
            ->and($html)->toContain('target="_blank"')
            ->and($html)->toContain($shared->getKey())
            ->and($html)->not->toContain($hidden->getKey())
            ->and($html)->not->toContain('hidden-paper-example.pdf');
    });

    it('mints the lightbox image of a visible PNG for the Partner, only when the modal is open', function (): void {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
        $shared = documentVisibilityBinaryFile($this->admin, $this->projectA, 'shared-picture-example.png', $pngBytes, true);
        documentVisibilityBinaryFile($this->admin, $this->projectA, 'hidden-picture-example.png', $pngBytes, false);
        $this->actingAs($this->partnerA);

        $tab = documentVisibilityProjectTab($this->projectA);

        expect($tab->html())->not->toContain('signature=');

        $tab->mountAction(TestAction::make('preview')->table($shared));

        $html = $tab->html();

        expect(preg_match('/<img[^>]*\ssrc="([^"]+)"/', $html, $match))->toBe(1);

        $src = html_entity_decode($match[1]);
        parse_str((string) parse_url($src, PHP_URL_QUERY), $query);

        expect($query['u'] ?? null)->toBe($this->partnerA->getKey())
            ->and($query['mode'] ?? null)->toBe('inline')
            ->and($src)->toContain($shared->getKey())
            ->and($html)->not->toContain('hidden-picture-example.png');
    });
});

describe('mutation self-check', function (): void {
    it('reports no violation for the real scope and for the hand-built reference that keeps both rules', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $viewers = ['a' => $this->partnerA, 'b' => Canary::partnerFor($this->clientB), 'none' => Canary::partnerFor(null)];

        $real = function (string $viewer) use ($viewers): array {
            $this->actingAs($viewers[$viewer]);

            return documentVisibilityScopedIds();
        };
        $reference = documentVisibilityHandBuiltProvider($this, $this->admin, $this->clientA, $this->clientB, flagRule: true, internalRule: true);

        expect(documentVisibilityViolations($world['kinds'], 'initial', $real))->toBe([])
            ->and(documentVisibilityViolations($world['kinds'], 'initial', $reference))->toBe([]);
    });

    it('names the hidden project file and the hidden task file when the scope loses the flag condition', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $leaky = documentVisibilityHandBuiltProvider($this, $this->admin, $this->clientA, $this->clientB, flagRule: false, internalRule: true);

        $violations = documentVisibilityViolations($world['kinds'], 'initial', $leaky);

        expect($violations)->toContain('a sees project_hidden')
            ->and($violations)->toContain('a sees task_hidden')
            ->and($violations)->not->toBe([]);
    });

    it('names the internal-comment file when the scope delegates to an unscoped comment set', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $leaky = documentVisibilityHandBuiltProvider($this, $this->admin, $this->clientA, $this->clientB, flagRule: true, internalRule: false);

        $violations = documentVisibilityViolations($world['kinds'], 'initial', $leaky);

        expect($violations)->toBe(['a sees internal_comment_visible']);
    });

    it('names a missing file as well, so a scope that hides too much fails the matrix too', function (): void {
        $world = documentVisibilityWorld($this->admin, $this->clientA, $this->clientB);
        $silent = static fn (string $viewer): array => [];

        $violations = documentVisibilityViolations($world['kinds'], 'initial', $silent);

        expect($violations)->toContain('a misses project_visible')
            ->and($violations)->toContain('b misses other_client_project_visible');
    });
});
