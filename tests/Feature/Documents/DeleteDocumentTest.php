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
use App\Filament\RelationManagers\ProjectDocumentsRelationManager;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
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
