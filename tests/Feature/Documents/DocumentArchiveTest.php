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
use App\Filament\Support\DocumentColumns;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

/**
 * The message of the refusal the request raises for the given ids, or null when the request went through.
 *
 * @param  array<array-key, mixed>  $ids
 */
function archiveRefusal(array $ids): ?string
{
    $admin = auth()->user();
    assert($admin instanceof User);

    try {
        app(RequestDocumentArchive::class)->handle($admin, $ids);
    } catch (ValidationException $e) {
        return $e->errors()['documents'][0] ?? null;
    }

    return null;
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

describe('the request limits', function (): void {
    it('refuses an empty selection and creates nothing', function (): void {
        Queue::fake();

        expect(archiveRefusal([]))->toBe(__('documents.archives.errors.empty'));

        Queue::assertNothingPushed();

        expect(DocumentArchive::query()->count())->toBe(0);
    });

    it('refuses more files than the limit and names the limit', function (): void {
        config(['kokpit.documents.archive.max_files' => 2]);
        $ids = [
            archiveFile('one-example.txt', 'Fictional one')->getKey(),
            archiveFile('two-example.txt', 'Fictional two')->getKey(),
            archiveFile('three-example.txt', 'Fictional three')->getKey(),
        ];

        Queue::fake();

        expect(archiveRefusal($ids))->toBe(__('documents.archives.errors.too_many', ['max' => 2]))
            ->and(__('documents.archives.errors.too_many', ['max' => 2]))->toContain('2');

        Queue::assertNothingPushed();

        expect(DocumentArchive::query()->count())->toBe(0);
    });

    it('accepts exactly the limit of files', function (): void {
        config(['kokpit.documents.archive.max_files' => 2]);
        $ids = [archiveFile('one-example.txt', 'Fictional one')->getKey(), archiveFile('two-example.txt', 'Fictional two')->getKey()];

        Queue::fake();

        expect(archiveRefusal($ids))->toBeNull();

        Queue::assertPushed(BuildDocumentArchive::class, 1);
    });

    it('refuses a selection larger than the byte limit and names the limit in MB', function (): void {
        $first = archiveFile('one-example.txt', str_repeat('a', 700000));
        $second = archiveFile('two-example.txt', str_repeat('b', 700000));
        config(['kokpit.documents.archive.max_bytes' => 1048576]);

        Queue::fake();

        $message = archiveRefusal([$first->getKey(), $second->getKey()]);

        expect($message)->toBe(__('documents.archives.errors.too_large', ['max' => DocumentColumns::humanSize(1048576)]))
            ->and($message)->toContain('1 MB');

        Queue::assertNothingPushed();

        expect(DocumentArchive::query()->count())->toBe(0);
    });

    it('accepts a selection of exactly the byte limit', function (): void {
        $first = archiveFile('one-example.txt', str_repeat('a', 500));
        $second = archiveFile('two-example.txt', str_repeat('b', 500));
        config(['kokpit.documents.archive.max_bytes' => 1000]);

        Queue::fake();

        expect(archiveRefusal([$first->getKey(), $second->getKey()]))->toBeNull();
    });

    it('refuses a value that is not a UUID, an unknown UUID and a duplicated id', function (): void {
        $known = archiveFile('known-example.txt', 'Fictional known')->getKey();

        Queue::fake();

        $unknown = __('documents.archives.errors.unknown');

        expect(archiveRefusal([$known, 'not-a-uuid']))->toBe($unknown)
            ->and(archiveRefusal([$known, (string) Str::uuid()]))->toBe($unknown)
            ->and(archiveRefusal([$known, $known]))->toBe($unknown)
            ->and(archiveRefusal([$known, ['nested']]))->toBe($unknown)
            ->and(archiveRefusal([$known, 12]))->toBe($unknown);

        Queue::assertNothingPushed();

        expect(DocumentArchive::query()->count())->toBe(0);
    });

    it('stores the ids, the count, the size and a summary of the selection', function (): void {
        $first = archiveFile('one-example.txt', str_repeat('a', 1024));
        $second = archiveFile('two-example.txt', str_repeat('b', 2048));
        $third = archiveFile('three-example.txt', str_repeat('c', 1024));

        Queue::fake();

        $archive = app(RequestDocumentArchive::class)->handle($this->admin, [$third->getKey(), $first->getKey(), $second->getKey()]);

        expect($archive->document_ids)->toBe([$third->getKey(), $first->getKey(), $second->getKey()])
            ->and($archive->document_count)->toBe(3)
            ->and($archive->total_bytes)->toBe(4096)
            ->and($archive->summary)->toBe('3 soubory, 4 KB');
    });
});

describe('the bulk action', function (): void {
    it('archives exactly the files of a filter result selected as a whole', function (): void {
        $hits = [archiveFile('invoice-one-example.txt', 'Fictional hit one'), archiveFile('invoice-two-example.txt', 'Fictional hit two')];
        archiveFile('contract-example.txt', 'Fictional other');

        Queue::fake();

        Livewire::test(ListDocuments::class)
            ->searchTable('invoice')
            ->set('isTrackingDeselectedTableRecords', true)
            ->set('deselectedTableRecords', [])
            ->mountAction(TestAction::make('bulkArchive')->table()->bulk())
            ->callMountedAction()
            ->assertNotified(__('documents.archives.queued.title'));

        $archive = DocumentArchive::query()->sole();

        expect($archive->document_ids)->toEqualCanonicalizing(collect($hits)->map(fn (Document $document): string => $document->getKey())->all())
            ->and($archive->document_count)->toBe(2);

        Queue::assertPushed(BuildDocumentArchive::class, 1);
    });

    it('shows the refusal as a danger notification and creates nothing', function (): void {
        config(['kokpit.documents.archive.max_files' => 1]);
        $first = archiveFile('one-example.txt', 'Fictional one');
        $second = archiveFile('two-example.txt', 'Fictional two');

        Queue::fake();

        Livewire::test(ListDocuments::class)
            ->mountTableBulkAction('bulkArchive', [$first->getKey(), $second->getKey()])
            ->callMountedTableBulkAction()
            ->assertNotified(__('documents.archives.errors.too_many', ['max' => 1]));

        Queue::assertNothingPushed();

        expect(DocumentArchive::query()->count())->toBe(0);
    });
});
