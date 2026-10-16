<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\RestoreTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * Archiving an owner hides its files, restoring brings them back, and nothing is ever deleted (DO-01, DO-03, D-21).
 * The Partner view comes from the Partner-scoped Project, Task and TaskComment queries; the default Admin listing
 * from Document::withoutArchivedOwners(). Every owner is archived and restored the way the application does it.
 * Every name and content is fictional.
 */

/**
 * A client-visible project of the given client, written through the domain Action.
 */
function archivedOwnerProject(Client $client, string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => 'Example archived-owner project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * Attaches one fictional file (visible by default where the owner can be shared) and returns the document.
 */
function archivedOwnerAttach(User $admin, ?Model $owner): Document
{
    /** @var Document */
    return app(AttachDocuments::class)->handle($admin, $owner, [DocumentFixtures::upload()])->firstOrFail();
}

/**
 * The sorted ids a query returns.
 *
 * @param  Builder<Document>  $query
 * @return list<string>
 */
function archivedOwnerIds(Builder $query): array
{
    /** @var list<string> $ids */
    $ids = $query->pluck('id')->all();
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * The sorted ids of the given documents.
 *
 * @return list<string>
 */
function archivedOwnerKeys(Document ...$documents): array
{
    $ids = array_map(static fn (Document $document): string => (string) $document->getKey(), $documents);
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * Asserts, as the Admin, that every document still has its row, exactly one media row and its stored object.
 */
function archivedOwnerAssertIntact(Document ...$documents): void
{
    foreach ($documents as $document) {
        expect(Document::query()->whereKey($document->getKey())->exists())->toBeTrue();

        $media = app(PartnerContext::class)->runAsSystem(
            static fn () => Media::query()->where('model_type', 'document')->where('model_id', $document->getKey())->get(),
        );

        expect($media)->toHaveCount(1)
            ->and(Storage::disk('local')->exists($media->first()->getPathRelativeToRoot()))->toBeTrue();
    }
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('hides the file of an archived project from the Partner and the default Admin query and brings it back on restore', function (): void {
    $client = Client::factory()->create();
    $project = archivedOwnerProject($client);
    $document = archivedOwnerAttach($this->admin, $project);
    $partner = Canary::partnerFor((string) $client->getKey());
    $expected = archivedOwnerKeys($document);

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe($expected);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe($expected)
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe([]);

    // Archived the way ProjectResource does it: a soft delete of the project.
    $project->delete();

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe([]);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe([])
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe($expected)
        ->and(archivedOwnerIds(Document::query()))->toBe($expected);
    archivedOwnerAssertIntact($document);

    $project->restore();

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe($expected);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe($expected)
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe([]);
    archivedOwnerAssertIntact($document);
});

/**
 * A client with one project, one task and one non-internal comment on it, and one file on each of the client, the
 * project, the task and the comment, all attached by the Admin.
 *
 * @return array{client: Client, project: Project, task: Task, comment: TaskComment, files: array{client: Document, project: Document, task: Document, comment: Document}}
 */
function archivedOwnerTree(User $admin, Client $client, string $key = 'ABC'): array
{
    $project = archivedOwnerProject($client, $key);
    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example archived-owner task']);
    $comment = app(AddTaskComment::class)->handle($admin, $task, 'Example archived-owner comment');

    return [
        'client' => $client,
        'project' => $project,
        'task' => $task,
        'comment' => $comment,
        'files' => [
            'client' => archivedOwnerAttach($admin, $client),
            'project' => archivedOwnerAttach($admin, $project),
            'task' => archivedOwnerAttach($admin, $task),
            'comment' => archivedOwnerAttach($admin, $comment),
        ],
    ];
}

it('moves the files of an archived client, its projects, tasks and comments to the archived view and back on restore', function (): void {
    $client = Client::factory()->create();
    $tree = archivedOwnerTree($this->admin, $client);
    $partner = Canary::partnerFor((string) $client->getKey());
    $all = array_values($tree['files']);
    $shared = archivedOwnerKeys($tree['files']['project'], $tree['files']['task'], $tree['files']['comment']);

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe($shared);

    $this->actingAs($this->admin);
    app(ArchiveClient::class)->handle($client);

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe([]);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe([])
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe(archivedOwnerKeys(...$all));
    archivedOwnerAssertIntact(...$all);

    app(RestoreClient::class)->handle($client);

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe($shared);

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe(archivedOwnerKeys(...$all))
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe([]);
    archivedOwnerAssertIntact(...$all);
});

it('hides the files of an archived task and of its comments but not the project file, and shows them again on restore', function (): void {
    $client = Client::factory()->create();
    $tree = archivedOwnerTree($this->admin, $client);
    $partner = Canary::partnerFor((string) $client->getKey());
    $everything = archivedOwnerKeys(...array_values($tree['files']));
    $kept = archivedOwnerKeys($tree['files']['client'], $tree['files']['project']);
    $archived = archivedOwnerKeys($tree['files']['task'], $tree['files']['comment']);

    app(ArchiveTask::class)->handle($this->admin, $tree['task']);

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe(archivedOwnerKeys($tree['files']['project']));

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe($kept)
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe($archived);
    archivedOwnerAssertIntact(...array_values($tree['files']));

    app(RestoreTask::class)->handle($this->admin, $tree['task']);

    $this->actingAs($partner);
    expect(archivedOwnerIds(Document::query()))->toBe(archivedOwnerKeys($tree['files']['project'], $tree['files']['task'], $tree['files']['comment']));

    $this->actingAs($this->admin);
    expect(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe($everything)
        ->and(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe([]);
    archivedOwnerAssertIntact(...array_values($tree['files']));
});

it('keeps the files of an active project of the same client while a sibling project is archived', function (): void {
    $client = Client::factory()->create();
    $archivedTree = archivedOwnerTree($this->admin, $client, 'ABC');
    $activeTree = archivedOwnerTree($this->admin, $client, 'XYZ');

    $archivedTree['project']->delete();

    // The client file belongs to the active client, so only the three files hanging on the archived project go.
    expect(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe(archivedOwnerKeys(
        $archivedTree['files']['project'],
        $archivedTree['files']['task'],
        $archivedTree['files']['comment'],
    ))->and(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe(archivedOwnerKeys(
        $archivedTree['files']['client'],
        ...array_values($activeTree['files']),
    ));
});

it('never counts an unattached file or a transaction receipt as a file of an archived owner', function (): void {
    $client = Client::factory()->create();
    $unattached = archivedOwnerAttach($this->admin, null);

    // A receipt of an archived client's transaction: written by hand, because transactions are never archived and
    // the receipt must stay findable whatever happens to the client (the owner kind is added with the transactions).
    $receipt = (new Document(['name' => 'Example receipt']))->forceFill([
        'owner_type' => 'transaction',
        'owner_id' => (string) Str::uuid7(),
        'client_id' => $client->getKey(),
        'uploaded_by_id' => $this->admin->getKey(),
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 1,
    ]);
    $receipt->save();

    app(ArchiveClient::class)->handle($client);

    expect(archivedOwnerIds(Document::query()->onlyArchivedOwners()))->toBe([])
        ->and(archivedOwnerIds(Document::query()->withoutArchivedOwners()))->toBe(archivedOwnerKeys($unattached, $receipt));
});

it('splits the documents into the active and the archived view with every document in exactly one of them', function (): void {
    $archivedTree = archivedOwnerTree($this->admin, Client::factory()->create(), 'ABC');
    $activeTree = archivedOwnerTree($this->admin, Client::factory()->create(), 'XYZ');
    archivedOwnerAttach($this->admin, null);

    app(ArchiveTask::class)->handle($this->admin, $activeTree['task']);
    $archivedTree['project']->delete();

    $without = archivedOwnerIds(Document::query()->withoutArchivedOwners());
    $only = archivedOwnerIds(Document::query()->onlyArchivedOwners());
    $every = archivedOwnerIds(Document::query());

    expect(array_intersect($without, $only))->toBe([])
        ->and([...$without, ...$only])->toHaveCount(count($every))
        ->and(archivedOwnerKeysFromIds([...$without, ...$only]))->toBe($every)
        ->and($only)->not->toBe([]);
});

/**
 * Sorts a list of ids.
 *
 * @param  list<string>  $ids
 * @return list<string>
 */
function archivedOwnerKeysFromIds(array $ids): array
{
    sort($ids, SORT_STRING);

    return $ids;
}
