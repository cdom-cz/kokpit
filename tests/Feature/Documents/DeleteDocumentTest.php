<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\RelationManagers\ProjectDocumentsRelationManager;
use App\Filament\RelationManagers\TaskDocumentsRelationManager;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\S3TestDisk;

/*
 * Permanent deletion of files (DO-03, D-09): the row, the media row and the stored object go together, after an
 * explicit confirmation, and every deletion leaves an activity row with the name, the size and the owner type.
 * Every name and content is fictional.
 */

/**
 * A client-visible project of a fresh client, written through the domain Action.
 */
function deletionProject(string $key = 'DEL'): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example deletion project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * Mounts the Soubory tab of a project as the Admin sees it on the detail page.
 */
function deletionTab(Project $project): Testable
{
    return Livewire::test(ProjectDocumentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class]);
}

/**
 * The media rows of a document, read as system because Media is closed to Partners.
 *
 * @return list<Media>
 */
function deletionMedia(string $documentId): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => Media::query()->where('model_id', $documentId)->get()->all(),
    );
}

/**
 * The path of the stored object of a document, relative to the root of its disk.
 */
function deletionObjectPath(Document $document): string
{
    $media = deletionMedia($document->id);

    expect($media)->toHaveCount(1);

    return $media[0]->getPathRelativeToRoot();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('deletes the row, the media and the stored object and records the deletion without the description', function (): void {
    $project = deletionProject();
    $document = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('delete-example.txt')], true, 'Private example description')
        ->firstOrFail();
    $path = deletionObjectPath($document);

    Storage::disk('local')->assertExists($path);

    app(DeleteDocument::class)->handle($this->admin, $document);

    $rows = Activity::query()->forSubject($document)->forEvent('deleted')->get();
    $old = (array) $rows->first()?->attribute_changes->get('old');
    ksort($old);

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(deletionMedia($document->id))->toBe([])
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($rows)->toHaveCount(1)
        ->and(array_keys($old))->toBe(['name', 'owner_type', 'size_bytes', 'visible_to_client'])
        ->and($old['name'])->toBe('delete-example.txt')
        ->and($old['owner_type'])->toBe('project')
        ->and($old['size_bytes'])->toBe($document->size_bytes)
        ->and(json_encode($rows->first()?->getAttributes()))->not->toContain('Private example description');
});

it('deletes through the table action only after the confirmation dialog', function (): void {
    $project = deletionProject();
    $document = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('confirm-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    $tab = deletionTab($project)
        ->assertActionExists(TestAction::make('delete')->table($document), static fn (Action $action): bool => $action->isConfirmationRequired())
        ->mountAction(TestAction::make('delete')->table($document));

    $mounted = $tab->instance()->getMountedAction();

    // Opening the dialog deletes nothing, and the dialog names the file and says it is permanent.
    expect(Document::query()->whereKey($document->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and((string) $mounted?->getModalDescription())->toBe(__('documents.actions.delete_description', ['name' => 'confirm-example.txt']))
        ->and((string) $mounted?->getModalHeading())->toBe(__('documents.actions.delete_heading'));

    $tab->callMountedAction();

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(deletionMedia($document->id))->toBe([])
        ->and(Storage::disk('local')->exists($path))->toBeFalse();

    $tab->assertNotified(__('documents.notifications.deleted'));
});

it('removes the object from the real bucket', function (): void {
    S3TestDisk::use();
    config(['media-library.disk_name' => 's3']);

    $project = deletionProject();
    $document = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('bucket-delete-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    try {
        expect(S3TestDisk::objects($path))->toBe([$path]);

        app(DeleteDocument::class)->handle($this->admin, $document);

        expect(S3TestDisk::objects($path))->toBe([])
            ->and(Storage::disk('s3')->exists($path))->toBeFalse()
            ->and(Document::query()->whereKey($document->id)->exists())->toBeFalse()
            ->and(deletionMedia($document->id))->toBe([]);
    } finally {
        Storage::disk('s3')->deleteDirectory(dirname($path));
    }
})->group('s3');

/**
 * Replaces the faked private disk by one that fails to delete the objects at the given paths: either by
 * throwing, as the s3 disk does, or by answering false without removing anything, as a local disk can.
 *
 * @param  list<string>  $paths
 */
function failDeletingOn(array $paths, bool $throw): void
{
    $real = Storage::disk('local');
    $failing = Mockery::mock($real)->makePartial();

    $failing->shouldReceive('delete')->andReturnUsing(static function (mixed $target) use ($real, $paths, $throw): bool {
        if (is_string($target) && in_array($target, $paths, true)) {
            if ($throw) {
                throw new RuntimeException('Fictional disk failure.');
            }

            return false;
        }

        return $real->delete($target);
    });

    Storage::set('local', $failing);
}

it('deletes the rows and leaves the reported object standing when the disk throws on delete (WR-01)', function (): void {
    $document = app(AttachDocuments::class)
        ->handle($this->admin, deletionProject(), [DocumentFixtures::upload('kept-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    failDeletingOn([$path], throw: true);
    Exceptions::fake();

    app(DeleteDocument::class)->handle($this->admin, $document);

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(deletionMedia($document->id))->toBe([])
        ->and(Activity::query()->forSubject($document)->forEvent('deleted')->count())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'could not be removed'));
    Exceptions::assertNotReported(static fn (Throwable $e): bool => str_contains($e->getMessage(), 'kept-example') || str_contains($e->getMessage(), $path));
});

it('deletes the rows and leaves the reported object standing when the disk answers false (WR-01)', function (): void {
    $document = app(AttachDocuments::class)
        ->handle($this->admin, deletionProject(), [DocumentFixtures::upload('stuck-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    failDeletingOn([$path], throw: false);
    Exceptions::fake();

    app(DeleteDocument::class)->handle($this->admin, $document);

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(deletionMedia($document->id))->toBe([])
        ->and(Activity::query()->forSubject($document)->forEvent('deleted')->count())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'could not be removed'));
    Exceptions::assertNotReported(static fn (Throwable $e): bool => str_contains($e->getMessage(), 'stuck-example') || str_contains($e->getMessage(), $path));
});

it('confirms the delete the Admin asked for even when the object cannot be removed afterwards, without naming it in the report (WR-01)', function (): void {
    $project = deletionProject();
    $document = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('failing-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    failDeletingOn([$path], throw: true);
    Exceptions::fake();

    deletionTab($project)
        ->callAction(TestAction::make('delete')->table($document))
        ->assertNotified(__('documents.notifications.deleted'));

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'could not be removed'));
    Exceptions::assertNotReported(static fn (Throwable $e): bool => str_contains($e->getMessage(), 'failing-example') || str_contains($e->getMessage(), $path));

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('removes the stored object only once the enclosing transaction commits (WR-01)', function (): void {
    $document = app(AttachDocuments::class)
        ->handle($this->admin, deletionProject(), [DocumentFixtures::upload('committed-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    DB::transaction(function () use ($document, $path): void {
        app(DeleteDocument::class)->handle($this->admin, $document);

        expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists($path))->toBeTrue();
    });

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(deletionMedia($document->id))->toBe([]);
});

it('keeps the row, the media row and the object when the enclosing transaction rolls back (WR-01)', function (): void {
    $document = app(AttachDocuments::class)
        ->handle($this->admin, deletionProject(), [DocumentFixtures::upload('rolled-back-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    try {
        DB::transaction(function () use ($document): void {
            app(DeleteDocument::class)->handle($this->admin, $document);

            throw new RuntimeException('Fictional later failure.');
        });
    } catch (RuntimeException) {
        // The failure after the delete is the point of the test.
    }

    expect(Document::query()->whereKey($document->id)->exists())->toBeTrue()
        ->and(deletionMedia($document->id))->toHaveCount(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Activity::query()->forSubject($document)->forEvent('deleted')->count())->toBe(0);
});

it('keeps everything and tells the Admin when the database refuses the delete (WR-01)', function (): void {
    $project = deletionProject();
    $document = app(AttachDocuments::class)
        ->handle($this->admin, $project, [DocumentFixtures::upload('refused-example.txt')])
        ->firstOrFail();
    $path = deletionObjectPath($document);

    Document::deleting(static function (): void {
        throw new RuntimeException('Fictional database refusal.');
    });
    Exceptions::fake();

    deletionTab($project)
        ->callAction(TestAction::make('delete')->table($document))
        ->assertNotified(__('documents.notifications.delete_failed'));

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'could not be deleted'));

    expect(Document::query()->whereKey($document->id)->exists())->toBeTrue()
        ->and(deletionMedia($document->id))->toHaveCount(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('deletes the files of an archived task for the Admin', function (): void {
    $task = app(CreateTask::class)->handle($this->admin, deletionProject('ARC'), ['title' => 'Example archived task']);
    $document = app(AttachDocuments::class)->handle($this->admin, $task, [DocumentFixtures::upload('archived-example.txt')])->firstOrFail();
    $path = deletionObjectPath($document);

    app(ArchiveTask::class)->handle($this->admin, $task);

    $archived = Task::query()->onlyTrashed()->findOrFail($task->id);

    Livewire::test(TaskDocumentsRelationManager::class, ['ownerRecord' => $archived, 'pageClass' => ViewTask::class])
        ->callAction(TestAction::make('delete')->table($document));

    expect(Document::query()->whereKey($document->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('deletes every selected file after one confirmation that names the number of files', function (): void {
    $project = deletionProject();
    $documents = collect(['one-example.txt', 'two-example.txt', 'three-example.txt'])
        ->map(fn (string $name): Document => app(AttachDocuments::class)->handle($this->admin, $project, [DocumentFixtures::upload($name)])->firstOrFail());
    $paths = $documents->map(fn (Document $document): string => deletionObjectPath($document));

    $tab = deletionTab($project)->mountTableBulkAction('bulkDelete', $documents);

    $mounted = $tab->instance()->getMountedAction();

    expect(Document::query()->count())->toBe(3)
        ->and((string) $mounted?->getModalDescription())->toBe(trans_choice('documents.actions.bulk_delete_description', 3, ['count' => 3]))
        ->and((string) $mounted?->getModalHeading())->toBe(__('documents.actions.bulk_delete_heading'));

    $tab->callMountedTableBulkAction();

    expect(Document::query()->count())->toBe(0)
        ->and($paths->filter(fn (string $path): bool => Storage::disk('local')->exists($path))->all())->toBe([]);

    $tab->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 3, 'failed' => 0]));
});

it('keeps going when one selected file was deleted in the meantime and reports both numbers', function (): void {
    $project = deletionProject();
    $documents = collect(['first-example.txt', 'second-example.txt', 'third-example.txt'])
        ->map(fn (string $name): Document => app(AttachDocuments::class)->handle($this->admin, $project, [DocumentFixtures::upload($name)])->firstOrFail());

    $tab = deletionTab($project)->mountTableBulkAction('bulkDelete', $documents);

    app(DeleteDocument::class)->handle($this->admin, $documents[1]);

    $tab->callMountedTableBulkAction();

    expect(Document::query()->count())->toBe(0);

    $tab->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 2, 'failed' => 1]));
});

it('deletes every selected file when the object of one cannot be removed and reports both numbers (WR-01)', function (): void {
    $project = deletionProject();
    $documents = collect(['alpha-example.txt', 'beta-example.txt', 'gamma-example.txt'])
        ->map(fn (string $name): Document => app(AttachDocuments::class)->handle($this->admin, $project, [DocumentFixtures::upload($name)])->firstOrFail());
    $refused = deletionObjectPath($documents[1]);

    failDeletingOn([$refused], throw: true);
    Exceptions::fake();

    deletionTab($project)
        ->callTableBulkAction('bulkDelete', $documents)
        ->assertNotified(__('documents.notifications.bulk_deleted', ['deleted' => 3, 'failed' => 0]));

    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($refused))->toBeTrue();
});
