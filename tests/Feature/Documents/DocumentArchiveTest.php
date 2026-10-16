<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\RequestDocumentArchive;
use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Documents\Jobs\BuildDocumentArchive;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The background ZIP of selected files (DO-02, D-08): the validated request behind the bulk action of the central
 * listing and the queued job that streams the stored objects into one archive on private storage. Every name and
 * content is fictional.
 */

/**
 * Attaches one unattached fictional file for the signed-in Admin and returns its document.
 */
function archiveFile(string $name, string $content): Document
{
    $admin = auth()->user();
    assert($admin instanceof User);

    /** @var Document */
    return app(AttachDocuments::class)->handle($admin, null, [DocumentFixtures::upload($name, $content)])->firstOrFail();
}

/**
 * Runs the job like a worker does: through its own middleware, so the system context is entered.
 */
function archiveRun(string $archiveId): void
{
    $job = new BuildDocumentArchive($archiveId);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (BuildDocumentArchive $job): mixed => app()->call($job->handle(...)));
}

/**
 * The archive record of the id, read as the signed-in Admin who owns it.
 */
function archiveRecord(string $archiveId): DocumentArchive
{
    return DocumentArchive::query()->findOrFail($archiveId);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('tracer: the Admin selects two files, asks for a ZIP, and the job leaves a ready archive with both files on private storage', function (): void {
    $first = archiveFile('first-note-example.txt', implode(' ', ['Fictional', 'first', 'content']));
    $second = archiveFile('second-note-example.txt', implode(' ', ['Fictional', 'second', 'content']));
    archiveFile('bystander-example.txt', implode(' ', ['Fictional', 'other', 'content']));

    Queue::fake();

    Livewire::test(ListDocuments::class)
        ->mountTableBulkAction('bulkArchive', [$first->getKey(), $second->getKey()])
        ->callMountedTableBulkAction()
        ->assertNotified(__('documents.archives.queued.title'));

    $archive = DocumentArchive::query()->sole();

    expect($archive->user_id)->toBe($this->admin->getKey())
        ->and($archive->status)->toBe(DocumentArchiveStatus::Pending)
        ->and($archive->document_count)->toBe(2)
        ->and($archive->document_ids)->toBe([$first->getKey(), $second->getKey()])
        ->and($archive->total_bytes)->toBe($first->size_bytes + $second->size_bytes);

    Queue::assertPushed(BuildDocumentArchive::class, static fn (BuildDocumentArchive $job): bool => $job->archiveId === $archive->getKey());

    archiveRun($archive->getKey());

    $ready = archiveRecord($archive->getKey());

    expect($ready->status)->toBe(DocumentArchiveStatus::Ready)
        ->and($ready->path)->toBe('document-archives/'.$archive->getKey().'.zip')
        ->and($ready->completed_at)->not->toBeNull()
        ->and($ready->expires_at->equalTo($ready->completed_at->addDays(7)))->toBeTrue()
        ->and(Storage::disk()->exists($ready->path))->toBeTrue();

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk()->path($ready->path)))->toBeTrue();

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName($first->name))->toBe(implode(' ', ['Fictional', 'first', 'content']))
        ->and($zip->getFromName($second->name))->toBe(implode(' ', ['Fictional', 'second', 'content']));

    $zip->close();
});

it('refuses a Partner and creates nothing', function (): void {
    $document = archiveFile('guarded-note-example.txt', implode(' ', ['Fictional', 'guarded', 'content']));
    $partner = Canary::partnerFor((string) Client::factory()->create()->getKey());

    Queue::fake();

    expect(fn () => app(RequestDocumentArchive::class)->handle($partner, [$document->getKey()]))
        ->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();

    expect(DocumentArchive::query()->withoutGlobalScopes()->count())->toBe(0);
});
