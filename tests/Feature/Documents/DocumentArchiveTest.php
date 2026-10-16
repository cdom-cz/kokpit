<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\RequestDocumentArchive;
use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Documents\Jobs\BuildDocumentArchive;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Notifications\DocumentArchiveFailedNotification;
use App\Domain\Documents\Notifications\DocumentArchiveReadyNotification;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\DocumentArchiveResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Support\DocumentColumns;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
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
 * Records a pending archive request of the given documents for the signed-in Admin; the build is not run.
 *
 * @param  list<Document>  $documents
 */
function archivePending(array $documents): DocumentArchive
{
    $admin = auth()->user();
    assert($admin instanceof User);

    Queue::fake();

    return app(RequestDocumentArchive::class)->handle($admin, array_map(static fn (Document $document): string => $document->getKey(), $documents));
}

/**
 * The temporary files of the archive build that are lying around.
 *
 * @return list<string>
 */
function archiveTemporaryFiles(): array
{
    return glob(sys_get_temp_dir().'/kokpit-zip-*') ?: [];
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

/**
 * The bell entries of a user as decoded arrays, newest first.
 *
 * @return list<array<string, mixed>>
 */
function archiveBell(User $user): array
{
    return DB::table('notifications')
        ->where('notifiable_id', $user->getKey())
        ->orderByDesc('created_at')
        ->get()
        ->map(static fn (object $row): array => json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR))
        ->all();
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

describe('the build job', function (): void {
    it('does nothing on a second run for the same record', function (): void {
        $archive = archivePending([archiveFile('once-example.txt', 'Fictional once')]);

        archiveRun($archive->getKey());

        $ready = archiveRecord($archive->getKey());
        $path = $ready->path;
        assert(is_string($path));
        Storage::disk()->put($path, 'marker of the first run');

        archiveRun($archive->getKey());

        expect(archiveRecord($archive->getKey())->completed_at?->equalTo($ready->completed_at))->toBeTrue()
            ->and(Storage::disk()->get($path))->toBe('marker of the first run')
            ->and(Storage::disk()->allFiles('document-archives'))->toBe([$path]);
    });

    it('does nothing for a failed record and for a record that does not exist', function (): void {
        $archive = archivePending([archiveFile('failed-example.txt', 'Fictional failed')]);
        $archive->markFailed();

        archiveRun($archive->getKey());
        archiveRun((string) Str::uuid());

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Failed)
            ->and(Storage::disk()->allFiles('document-archives'))->toBe([]);
    });

    it('overwrites the object of an earlier attempt that died after writing and becomes ready', function (): void {
        $archive = archivePending([archiveFile('rerun-example.txt', 'Fictional rerun')]);
        Storage::disk()->put($archive->storagePath(), 'half of an earlier attempt');

        archiveRun($archive->getKey());

        $zip = new ZipArchive;
        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready)
            ->and(Storage::disk()->allFiles('document-archives'))->toBe([$archive->storagePath()])
            ->and($zip->open(Storage::disk()->path($archive->storagePath())))->toBeTrue();

        expect($zip->numFiles)->toBe(1)
            ->and($zip->getFromName('rerun-example.txt'))->toBe('Fictional rerun');

        $zip->close();
    });

    it('removes its temporary file after a success and after an exception', function (): void {
        $before = archiveTemporaryFiles();

        $good = archivePending([archiveFile('good-example.txt', 'Fictional good')]);
        archiveRun($good->getKey());

        expect(archiveTemporaryFiles())->toBe($before);

        $broken = archiveFile('broken-example.txt', 'Fictional broken');
        $brokenArchive = archivePending([$broken]);
        Storage::disk()->deleteDirectory('');

        expect(fn () => archiveRun($brokenArchive->getKey()))->toThrow(RuntimeException::class)
            ->and(archiveTemporaryFiles())->toBe($before)
            ->and(archiveRecord($brokenArchive->getKey())->status)->toBe(DocumentArchiveStatus::Pending)
            ->and(Storage::disk()->exists($brokenArchive->storagePath()))->toBeFalse();
    });

    it('leaves out a file that was deleted after the request', function (): void {
        $kept = archiveFile('kept-example.txt', 'Fictional kept');
        $gone = archiveFile('gone-example.txt', 'Fictional gone');
        $archive = archivePending([$kept, $gone]);
        $gone->delete();

        archiveRun($archive->getKey());

        $zip = new ZipArchive;
        expect($zip->open(Storage::disk()->path($archive->storagePath())))->toBeTrue();

        expect($zip->numFiles)->toBe(1)
            ->and($zip->getFromName('kept-example.txt'))->toBe('Fictional kept');

        $zip->close();
    });

    it('reads a file the Partner cannot see, because the job runs as system', function (): void {
        $client = Client::factory()->create();
        $hidden = app(AttachDocuments::class)->handle($this->admin, $client, [DocumentFixtures::upload('hidden-example.txt', 'Fictional hidden')])->firstOrFail();
        $archive = archivePending([$hidden]);

        $this->actingAs(Canary::partnerFor((string) $client->getKey()));

        // The Partner reads neither the file nor the archive record ...
        expect(Document::query()->count())->toBe(0)
            ->and(DocumentArchive::query()->count())->toBe(0);

        // ... but the job, entered through its own middleware, does.
        archiveRun($archive->getKey());

        $this->actingAs($this->admin);

        $zip = new ZipArchive;
        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready)
            ->and($zip->open(Storage::disk()->path($archive->storagePath())))->toBeTrue();

        expect($zip->getFromName('hidden-example.txt'))->toBe('Fictional hidden');

        $zip->close();
    });

    it('declares its idempotence and a timeout below the supervisor', function (): void {
        $timeout = (new ReflectionClass(BuildDocumentArchive::class))->getAttributes(Timeout::class);

        expect($timeout)->toHaveCount(1)
            ->and($timeout[0]->newInstance()->timeout)->toBeLessThan(300);
    });
});

describe('the bells', function (): void {
    it('rings one bell for the requesting Admin with the count, the expiry day and a link to the archives list', function (): void {
        $archive = archivePending([
            archiveFile('first-bell-example.txt', 'Fictional first'),
            archiveFile('second-bell-example.txt', 'Fictional second'),
        ]);
        $otherAdmin = Canary::admin();
        $partner = Canary::partnerFor((string) Client::factory()->create()->getKey());

        archiveRun($archive->getKey());

        $ready = archiveRecord($archive->getKey());
        $bell = archiveBell($this->admin);

        expect($bell)->toHaveCount(1)
            ->and($bell[0]['title'])->toBe(__('documents.archives.bell.ready.title'))
            ->and($bell[0]['body'])->toBe(trans_choice('documents.archives.bell.ready.body', 2, [
                'count' => 2,
                'date' => $ready->expires_at->setTimezone('Europe/Prague')->format('j. n. Y'),
            ]))
            ->and($bell[0]['body'])->toContain('2 soubory')
            ->and($bell[0]['actions'][0]['url'])->toBe(DocumentArchiveResource::getUrl('index'))
            ->and($bell[0]['actions'][0]['url'])->toEndWith('/admin/document-archives')
            ->and(archiveBell($otherAdmin))->toBe([])
            ->and(archiveBell($partner))->toBe([]);
    });

    it('sends the failure bell and marks the record failed when the queue gives up on the job', function (): void {
        $archive = archivePending([archiveFile('broken-bell-example.txt', 'Fictional broken')]);
        Storage::disk()->deleteDirectory('');
        $otherAdmin = Canary::admin();

        expect(fn () => archiveRun($archive->getKey()))->toThrow(RuntimeException::class)
            ->and(archiveBell($this->admin))->toBe([]);

        // The worker calls failed() once the attempts are used up.
        (new BuildDocumentArchive($archive->getKey()))->failed(new RuntimeException('gave up'));

        $bell = archiveBell($this->admin);

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Failed)
            ->and($bell)->toHaveCount(1)
            ->and($bell[0]['title'])->toBe(__('documents.archives.bell.failed.title'))
            ->and($bell[0]['body'])->toBe(__('documents.archives.bell.failed.body'))
            ->and($bell[0]['actions'][0]['url'])->toBe(DocumentArchiveResource::getUrl('index'))
            ->and(archiveBell($otherAdmin))->toBe([]);
    });

    it('leaves a ready or missing record alone when a late failure arrives', function (): void {
        $archive = archivePending([archiveFile('late-bell-example.txt', 'Fictional late')]);
        archiveRun($archive->getKey());

        (new BuildDocumentArchive($archive->getKey()))->failed(new RuntimeException('late'));
        (new BuildDocumentArchive((string) Str::uuid()))->failed(new RuntimeException('gone'));

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready)
            ->and(archiveBell($this->admin))->toHaveCount(1);
    });

    it('sends no bell when the transaction that marks the record ready rolls back', function (): void {
        $archive = archivePending([archiveFile('rolled-bell-example.txt', 'Fictional rolled')]);

        try {
            DB::transaction(function () use ($archive): void {
                archiveRun($archive->getKey());

                throw new RuntimeException('rolled back after the job');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Pending)
            ->and(archiveBell($this->admin))->toBe([]);
    });

    it('escapes a value once and goes to the database only, not through the queue', function (): void {
        $ready = new DocumentArchiveReadyNotification(3, '<b>1. 1. 2027</b> & y', '/admin/document-archives');
        $failed = new DocumentArchiveFailedNotification('/admin/document-archives');

        expect($ready->toDatabase($this->admin)['body'])->toBe('ZIP obsahuje 3 soubory. Je ke stažení do &lt;b&gt;1. 1. 2027&lt;/b&gt; &amp; y.')
            ->and($ready->via($this->admin))->toBe(['database'])
            ->and($failed->via($this->admin))->toBe(['database'])
            ->and($ready)->not->toBeInstanceOf(ShouldQueue::class)
            ->and($failed)->not->toBeInstanceOf(ShouldQueue::class);
    });
});

describe('the entry names and the limits of the job', function (): void {
    it('keeps two documents of the same name apart inside the ZIP with the right bytes', function (): void {
        $first = archiveFile('Faktura.pdf', 'Fictional first invoice');
        $second = archiveFile('Faktura.pdf', 'Fictional second invoice');
        $third = archiveFile('faktura.PDF', 'Fictional third invoice');
        $archive = archivePending([$first, $second, $third]);

        archiveRun($archive->getKey());

        $zip = new ZipArchive;
        expect($zip->open(Storage::disk()->path($archive->storagePath())))->toBeTrue();

        expect($zip->numFiles)->toBe(3)
            ->and($zip->getFromName('Faktura.pdf'))->toBe('Fictional first invoice')
            ->and($zip->getFromName('Faktura (2).pdf'))->toBe('Fictional second invoice')
            ->and($zip->getFromName('faktura (3).pdf'))->toBe('Fictional third invoice');

        $zip->close();
    });

    it('builds the queued ZIP of a.pdf, a.pdf and a (2).pdf with three distinct entries and only the ready bell (CR-01)', function (): void {
        $contents = [
            implode(' ', ['Fictional', 'first', 'plain', 'a']),
            implode(' ', ['Fictional', 'second', 'plain', 'a']),
            implode(' ', ['Fictional', 'numbered', 'a']),
        ];
        $first = archiveFile('a.pdf', $contents[0]);
        $second = archiveFile('a.pdf', $contents[1]);
        $numbered = archiveFile('a (2).pdf', $contents[2]);

        expect([$first->name, $second->name, $numbered->name])->toBe(['a.pdf', 'a.pdf', 'a (2).pdf']);

        // No Queue::fake(): the sync connection runs the job like a worker, with its middleware and failed().
        $archive = app(RequestDocumentArchive::class)->handle($this->admin, [$first->getKey(), $second->getKey(), $numbered->getKey()]);
        $bell = archiveBell($this->admin);
        $titles = array_column($bell, 'title');

        $zip = new ZipArchive;
        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready)
            ->and($zip->open(Storage::disk()->path($archive->storagePath())))->toBeTrue();

        expect($zip->numFiles)->toBe(3)
            ->and($zip->getFromName('a.pdf'))->toBe($contents[0])
            ->and($zip->getFromName('a (2).pdf'))->toBe($contents[1])
            ->and($zip->getFromName('a (2) (2).pdf'))->toBe($contents[2])
            ->and($bell)->toHaveCount(1)
            ->and($titles)->toBe([__('documents.archives.bell.ready.title')])
            ->and($titles)->not->toContain(__('documents.archives.bell.failed.title'));

        $zip->close();
    });

    it('builds the queued ZIP of the reverse order a (2).pdf, a.pdf, a.pdf (CR-01)', function (): void {
        $contents = [
            implode(' ', ['Fictional', 'numbered', 'a']),
            implode(' ', ['Fictional', 'first', 'plain', 'a']),
            implode(' ', ['Fictional', 'second', 'plain', 'a']),
        ];
        $numbered = archiveFile('a (2).pdf', $contents[0]);
        $first = archiveFile('a.pdf', $contents[1]);
        $second = archiveFile('a.pdf', $contents[2]);

        expect([$numbered->name, $first->name, $second->name])->toBe(['a (2).pdf', 'a.pdf', 'a.pdf']);

        $archive = app(RequestDocumentArchive::class)->handle($this->admin, [$numbered->getKey(), $first->getKey(), $second->getKey()]);
        $bell = archiveBell($this->admin);
        $titles = array_column($bell, 'title');

        $zip = new ZipArchive;
        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready)
            ->and($zip->open(Storage::disk()->path($archive->storagePath())))->toBeTrue();

        expect($zip->numFiles)->toBe(3)
            ->and($zip->getFromName('a (2).pdf'))->toBe($contents[0])
            ->and($zip->getFromName('a.pdf'))->toBe($contents[1])
            ->and($zip->getFromName('a (3).pdf'))->toBe($contents[2])
            ->and($bell)->toHaveCount(1)
            ->and($titles)->toBe([__('documents.archives.bell.ready.title')])
            ->and($titles)->not->toContain(__('documents.archives.bell.failed.title'));

        $zip->close();
    });

    it('writes no entry name with a separator or a parent segment, even for a name stored that way', function (): void {
        $document = archiveFile('plain-example.txt', 'Fictional plain');
        // Stored names are cleaned on upload, but the job must not rely on that.
        $document->forceFill(['name' => implode('/', ['..', '..', 'escape.txt'])])->saveQuietly();
        $archive = archivePending([$document]);

        archiveRun($archive->getKey());

        $zip = new ZipArchive;
        expect($zip->open(Storage::disk()->path($archive->storagePath())))->toBeTrue();

        $name = (string) $zip->getNameIndex(0);

        expect($zip->numFiles)->toBe(1)
            ->and($name)->not->toContain('/')
            ->and($name)->not->toContain('..')
            ->and($name)->not->toStartWith('.')
            ->and($zip->getFromIndex(0))->toBe('Fictional plain');

        $zip->close();
    });

    it('counts only the included files in the ready bell when a file was deleted after the request', function (): void {
        $kept = archiveFile('kept-example.txt', 'Fictional kept');
        $gone = archiveFile('gone-example.txt', 'Fictional gone');
        $archive = archivePending([$kept, $gone]);
        $gone->delete();

        archiveRun($archive->getKey());

        $bell = archiveBell($this->admin);

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready)
            ->and($bell)->toHaveCount(1)
            ->and($bell[0]['body'])->toContain('1 soubor');
    });

    it('fails with the failure bell, without throwing, when every document was deleted', function (): void {
        $lonely = archiveFile('lonely-example.txt', 'Fictional lonely');
        $archive = archivePending([$lonely]);
        $lonely->delete();

        archiveRun($archive->getKey());

        $bell = archiveBell($this->admin);

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Failed)
            ->and(Storage::disk()->exists($archive->storagePath()))->toBeFalse()
            ->and($bell)->toHaveCount(1)
            ->and($bell[0]['title'])->toBe(__('documents.archives.bell.failed.title'));
    });

    it('fails with the failure bell when the file limit was lowered below the stored count', function (): void {
        $archive = archivePending([archiveFile('one-example.txt', 'Fictional one'), archiveFile('two-example.txt', 'Fictional two')]);
        config(['kokpit.documents.archive.max_files' => 1]);

        archiveRun($archive->getKey());

        $bell = archiveBell($this->admin);

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Failed)
            ->and(Storage::disk()->allFiles('document-archives'))->toBe([])
            ->and($bell)->toHaveCount(1)
            ->and($bell[0]['title'])->toBe(__('documents.archives.bell.failed.title'));
    });

    it('fails with the failure bell when the files outgrew the byte limit', function (): void {
        $before = archiveTemporaryFiles();
        $archive = archivePending([archiveFile('big-example.txt', str_repeat('a', 2000))]);
        config(['kokpit.documents.archive.max_bytes' => 1000]);

        archiveRun($archive->getKey());

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Failed)
            ->and(archiveBell($this->admin))->toHaveCount(1)
            ->and(archiveTemporaryFiles())->toBe($before);
    });

    it('builds an archive of exactly the configured limits', function (): void {
        $archive = archivePending([archiveFile('one-example.txt', str_repeat('a', 500)), archiveFile('two-example.txt', str_repeat('b', 500))]);
        config(['kokpit.documents.archive.max_files' => 2, 'kokpit.documents.archive.max_bytes' => 1000]);

        archiveRun($archive->getKey());

        expect(archiveRecord($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready);
    });
});
