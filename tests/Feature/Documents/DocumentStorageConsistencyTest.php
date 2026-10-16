<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Actions\RestoreClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Actions\RenameDocument;
use App\Domain\Documents\Jobs\RemoveDocumentObjects;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentOwnerResolver;
use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Actions\UpdateTransaction;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * Storage consistency (DO-01, DO-03, FI-01, D-09, D-11, D-21, research Pitfall 5 and Open Question 4): the check that
 * a later health indicator can reuse. After a mixed life of uploads, renames, deletes, a transaction that changed its
 * client, a deleted transaction, a failed upload and the archiving and restoring of a task, a project and a client,
 * the three stores agree: every document has exactly one media row, every document media row has its document, every
 * stored object belongs to a media row and every media row has its object, and the client and project of every file
 * equal what its owner resolves to. Archiving and restoring change neither rows nor objects. Everything is
 * fictional.
 */

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function consistencySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Names every disagreement between the documents table, the media table and the objects of the faked disk. An empty
 * list means the stores agree. This is the reusable check: it only reads.
 *
 * @return list<string>
 */
function consistencyViolations(): array
{
    return consistencySystem(static function (): array {
        $violations = [];
        $disk = Storage::disk('local');

        $documents = Document::query()->get();
        $media = Media::query()->where('model_type', (new Document)->getMorphClass())->get();
        $mediaByDocument = $media->groupBy('model_id');
        $documentIds = $documents->pluck('id')->all();

        foreach ($documents as $document) {
            $count = $mediaByDocument->get($document->getKey())?->count() ?? 0;

            if ($count !== 1) {
                $violations[] = "document {$document->getKey()} has {$count} media rows";
            }
        }

        foreach ($media as $row) {
            if (! in_array($row->model_id, $documentIds, true)) {
                $violations[] = "media {$row->getKey()} has no document";
            }

            if (! $disk->exists($row->getPathRelativeToRoot())) {
                $violations[] = "media {$row->getKey()} has no object";
            }
        }

        // An orphan object is what a delete or a commit that failed halfway leaves behind.
        $known = $media->map(static fn (Media $row): string => $row->getPathRelativeToRoot())->all();

        foreach ($disk->allFiles() as $path) {
            if (str_starts_with($path, 'livewire-tmp/')) {
                continue;
            }

            if (! in_array($path, $known, true)) {
                $violations[] = "object {$path} has no media row";
            }
        }

        $resolver = app(DocumentOwnerResolver::class);

        foreach ($documents as $document) {
            if ($document->owner_type === null) {
                if ($document->client_id !== null || $document->project_id !== null) {
                    $violations[] = "document {$document->getKey()} is unattached but carries a client or project";
                }

                continue;
            }

            $owner = consistencyOwner($document);

            if ($owner === null) {
                $violations[] = "document {$document->getKey()} has no owner row";

                continue;
            }

            $resolved = $resolver->resolve($owner);

            if ($document->client_id !== $resolved['client_id'] || $document->project_id !== $resolved['project_id']) {
                $violations[] = "document {$document->getKey()} has a client or project that differs from its owner";
            }
        }

        sort($violations, SORT_STRING);

        return $violations;
    });
}

/**
 * The owner row of a document, archived rows included.
 */
function consistencyOwner(Document $document): ?Model
{
    $class = Relation::getMorphedModel((string) $document->owner_type);

    if ($class === null) {
        return null;
    }

    $query = $class::query();

    if (method_exists($class, 'bootSoftDeletes')) {
        $query->withTrashed();
    }

    return $query->whereKey($document->owner_id)->first();
}

/**
 * The counts that archiving and restoring must leave alone: documents, document media rows and stored objects.
 *
 * @return array{documents: int, media: int, objects: list<string>}
 */
function consistencyCounts(): array
{
    return consistencySystem(static function (): array {
        $objects = Storage::disk('local')->allFiles();
        sort($objects, SORT_STRING);

        return [
            'documents' => Document::query()->count(),
            'media' => Media::query()->where('model_type', (new Document)->getMorphClass())->count(),
            'objects' => $objects,
        ];
    });
}

/**
 * One fictional file attached to the owner as the Admin.
 */
function consistencyAttach(User $admin, ?Model $owner, string $name): Document
{
    /** @var Document */
    return consistencySystem(static fn (): Document => app(AttachDocuments::class)
        ->handle($admin, $owner, [DocumentFixtures::upload($name)])
        ->firstOrFail());
}

/**
 * Runs a queued removal job like a worker does: through its own middleware.
 */
function consistencyRunQueued(RemoveDocumentObjects $job): void
{
    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (RemoveDocumentObjects $job): mixed => app()->call($job->handle(...)));
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

describe('the storage consistency check', function (): void {
    it('finds nothing in an empty system', function (): void {
        expect(consistencyViolations())->toBe([]);
    });

    it('stays clean through a mixed life of uploads on every owner kind, renames, deletes and a failed upload', function (): void {
        $client = Client::factory()->create();
        $project = app(CreateProject::class)->handle($client, ['name' => 'Example consistency project', 'key' => 'CON', 'billing_type' => 'hourly', 'client_visible' => true]);
        $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example consistency task']);
        $comment = app(AddTaskComment::class)->handle($this->admin, $task, 'Example consistency comment');
        $category = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->where('name', 'Software')->sole();
        $transaction = app(RecordTransaction::class)->handle($this->admin, [
            'type' => 'expense',
            'transaction_date' => '2026-10-14',
            'category_id' => $category->getKey(),
            'amount' => '100,00',
            'currency' => 'CZK',
            'client_id' => $client->getKey(),
            'project_id' => $project->getKey(),
        ]);

        $files = [
            'client' => consistencyAttach($this->admin, $client, 'client-example.txt'),
            'project' => consistencyAttach($this->admin, $project, 'project-example.txt'),
            'task' => consistencyAttach($this->admin, $task, 'task-example.txt'),
            'comment' => consistencyAttach($this->admin, $comment, 'comment-example.txt'),
            'receipt' => consistencyAttach($this->admin, $transaction, 'receipt-example.txt'),
            'unattached' => consistencyAttach($this->admin, null, 'unattached-example.txt'),
        ];

        expect(consistencyViolations())->toBe([])
            ->and(Document::query()->count())->toBe(6);

        app(RenameDocument::class)->handle($this->admin, $files['project'], 'renamed-example', null);
        app(DeleteDocument::class)->handle($this->admin, $files['task']);

        // A failed upload (the third file fails) leaves nothing behind.
        $created = 0;
        Media::creating(static function () use (&$created): void {
            if (++$created === 3) {
                throw new RuntimeException('forced media failure');
            }
        });

        $before = consistencyCounts();

        expect(fn () => app(AttachDocuments::class)->handle($this->admin, $project, [DocumentFixtures::upload(), DocumentFixtures::upload(), DocumentFixtures::upload()]))
            ->toThrow(RuntimeException::class, 'forced media failure')
            ->and(consistencyCounts())->toBe($before);

        // The transaction moves to another client, then loses its project; its receipt follows (D-11).
        $other = Client::factory()->create();
        app(UpdateTransaction::class)->handle($this->admin, $transaction, ['client_id' => $other->getKey(), 'project_id' => null]);

        expect(consistencyViolations())->toBe([])
            ->and($files['receipt']->refresh()->client_id)->toBe($other->getKey());

        // Deleting the transaction deletes the receipt with its object.
        app(DeleteTransaction::class)->handle($this->admin, $transaction->refresh());

        expect(consistencyViolations())->toBe([])
            ->and(Document::query()->count())->toBe(4);
    });

    it('changes neither rows nor objects when a task, a project and a client are archived and restored', function (): void {
        $client = Client::factory()->create();
        $project = app(CreateProject::class)->handle($client, ['name' => 'Example consistency project', 'key' => 'ARC', 'billing_type' => 'hourly', 'client_visible' => true]);
        $task = app(CreateTask::class)->handle($this->admin, $project, ['title' => 'Example consistency task']);
        $comment = app(AddTaskComment::class)->handle($this->admin, $task, 'Example consistency comment');

        consistencyAttach($this->admin, $client, 'client-example.txt');
        consistencyAttach($this->admin, $project, 'project-example.txt');
        consistencyAttach($this->admin, $task, 'task-example.txt');
        consistencyAttach($this->admin, $comment, 'comment-example.txt');

        $before = consistencyCounts();

        expect($before['documents'])->toBe(4)
            ->and($before['media'])->toBe(4)
            ->and($before['objects'])->toHaveCount(4)
            ->and(consistencyViolations())->toBe([]);

        // The task, then the project, then the client go to the archive and come back, one step at a time.
        app(ArchiveTask::class)->handle($this->admin, $task);
        expect(consistencyCounts())->toBe($before)->and(consistencyViolations())->toBe([]);

        app(RestoreTask::class)->handle($this->admin, $task->refresh());
        expect(consistencyCounts())->toBe($before)->and(consistencyViolations())->toBe([]);

        consistencySystem(static fn () => $project->delete());
        expect(consistencyCounts())->toBe($before)->and(consistencyViolations())->toBe([]);

        consistencySystem(static fn () => $project->restore());
        expect(consistencyCounts())->toBe($before)->and(consistencyViolations())->toBe([]);

        app(ArchiveClient::class)->handle($client);
        expect(consistencyCounts())->toBe($before)->and(consistencyViolations())->toBe([]);

        app(RestoreClient::class)->handle($client->refresh());
        expect(consistencyCounts())->toBe($before)->and(consistencyViolations())->toBe([]);
    });
});

describe('the check names every kind of drift', function (): void {
    it('names a document whose media row is gone', function (): void {
        $document = consistencyAttach($this->admin, null, 'example.txt');

        consistencySystem(static fn () => DB::table('media')->where('model_id', $document->getKey())->delete());

        expect(consistencyViolations())->toContain("document {$document->getKey()} has 0 media rows");
    });

    it('names a media row whose document is gone and the object that stays behind with it', function (): void {
        $document = consistencyAttach($this->admin, null, 'example.txt');
        $media = consistencySystem(static fn (): Media => Media::query()->where('model_id', $document->getKey())->sole());

        consistencySystem(static fn () => DB::table('documents')->where('id', $document->getKey())->delete());

        expect(consistencyViolations())->toContain("media {$media->getKey()} has no document");
    });

    it('names a media row whose object is gone', function (): void {
        $document = consistencyAttach($this->admin, null, 'example.txt');
        $media = consistencySystem(static fn (): Media => Media::query()->where('model_id', $document->getKey())->sole());

        Storage::disk('local')->delete($media->getPathRelativeToRoot());

        expect(consistencyViolations())->toContain("media {$media->getKey()} has no object");
    });

    it('names an orphan object that a delete or a commit left behind', function (): void {
        consistencyAttach($this->admin, null, 'example.txt');
        Storage::disk('local')->put('orphan/example.txt', 'Fictional orphan content');

        expect(consistencyViolations())->toBe(['object orphan/example.txt has no media row']);
    });

    it('names a file whose client or project differs from what its owner resolves to', function (): void {
        $client = Client::factory()->create();
        $project = app(CreateProject::class)->handle($client, ['name' => 'Example consistency project', 'key' => 'DRF', 'billing_type' => 'hourly', 'client_visible' => true]);
        $document = consistencyAttach($this->admin, $project, 'example.txt');

        consistencySystem(static fn () => DB::table('documents')->where('id', $document->getKey())->update(['project_id' => null]));

        expect(consistencyViolations())->toBe(["document {$document->getKey()} has a client or project that differs from its owner"]);
    });
});

describe('the delete paths keep the stores consistent (WR-01)', function (): void {
    it('stays consistent when a transaction delete rolls back after its receipts went (WR-01)', function (): void {
        $category = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->where('name', 'Software')->sole();
        $transaction = app(RecordTransaction::class)->handle($this->admin, [
            'type' => 'expense',
            'transaction_date' => '2026-10-14',
            'category_id' => $category->getKey(),
            'amount' => '100,00',
            'currency' => 'CZK',
        ]);

        consistencyAttach($this->admin, $transaction, 'first-receipt-example.txt');
        consistencyAttach($this->admin, $transaction, 'second-receipt-example.txt');

        $before = consistencyCounts();

        expect($before['documents'])->toBe(2)
            ->and(consistencyViolations())->toBe([]);

        Queue::fake();
        Transaction::deleting(static function (): void {
            throw new RuntimeException('Fictional database refusal.');
        });

        expect(fn () => app(DeleteTransaction::class)->handle($this->admin, $transaction->refresh()))
            ->toThrow(RuntimeException::class, 'Fictional database refusal.')
            ->and(consistencyCounts())->toBe($before)
            ->and(consistencyViolations())->toBe([]);

        Queue::assertNotPushed(RemoveDocumentObjects::class);
    });

    it('stays consistent when an enclosing transaction rolls back after a file was deleted (WR-01)', function (): void {
        $client = Client::factory()->create();
        $project = app(CreateProject::class)->handle($client, ['name' => 'Example consistency project', 'key' => 'ENC', 'billing_type' => 'hourly', 'client_visible' => true]);
        $document = consistencyAttach($this->admin, $project, 'enclosed-example.txt');

        $before = consistencyCounts();

        Queue::fake();

        expect(fn () => DB::transaction(function () use ($document): void {
            app(DeleteDocument::class)->handle($this->admin, $document);

            throw new RuntimeException('Fictional enclosing failure.');
        }))->toThrow(RuntimeException::class, 'Fictional enclosing failure.')
            ->and(consistencyCounts())->toBe($before)
            ->and(consistencyViolations())->toBe([]);

        Queue::assertNotPushed(RemoveDocumentObjects::class);
    });

    it('names the object a refused removal leaves and is clean again once the queued removal ran (WR-01)', function (): void {
        $client = Client::factory()->create();
        $project = app(CreateProject::class)->handle($client, ['name' => 'Example consistency project', 'key' => 'REF', 'billing_type' => 'hourly', 'client_visible' => true]);
        $document = consistencyAttach($this->admin, $project, 'refused-example.txt');
        $media = consistencySystem(static fn (): Media => Media::query()->where('model_id', $document->getKey())->sole());
        $path = $media->getPathRelativeToRoot();

        Queue::fake();

        $real = Storage::disk('local');
        $refusing = Mockery::mock($real)->makePartial();
        $refusing->shouldReceive('delete')->andReturnUsing(static function (mixed $target) use ($real, $path): bool {
            if ($target === $path) {
                throw new RuntimeException('Fictional disk failure.');
            }

            return $real->delete($target);
        });
        Storage::set('local', $refusing);

        app(DeleteDocument::class)->handle($this->admin, $document);

        expect(consistencyViolations())->toBe(["object {$path} has no media row"]);

        // The disk works again; the queued job removes exactly the object that was left.
        Storage::set('local', $real);

        Queue::assertPushed(RemoveDocumentObjects::class, 1);

        $job = Queue::pushed(RemoveDocumentObjects::class)->sole();

        expect($job->objects)->toBe([['disk' => 'local', 'path' => $path]]);

        consistencyRunQueued($job);

        expect(consistencyViolations())->toBe([])
            ->and(Storage::disk('local')->exists($path))->toBeFalse();
    });
});
