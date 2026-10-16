<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\RequestDocumentArchive;
use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Documents\Jobs\BuildDocumentArchive;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * WR-02 (D-08): the ZIP of an archive record that failed, vanished or was given up on does not stay on the private
 * bucket. The job removes it after the deciding transaction commits, never the ZIP of a ready record, and a failing
 * removal never stops the failure handling. Every name and content is fictional.
 */

/**
 * Attaches one unattached fictional file for the signed-in Admin and returns its document.
 */
function archiveCleanupFile(string $name, string $content): Document
{
    $admin = auth()->user();
    assert($admin instanceof User);

    /** @var Document */
    return app(AttachDocuments::class)->handle($admin, null, [DocumentFixtures::upload($name, $content)])->firstOrFail();
}

/**
 * Records a pending archive request of the given documents for the signed-in Admin; the build is not run.
 *
 * @param  list<Document>  $documents
 */
function archiveCleanupPending(array $documents): DocumentArchive
{
    $admin = auth()->user();
    assert($admin instanceof User);

    Queue::fake();

    return app(RequestDocumentArchive::class)->handle($admin, array_map(static fn (Document $document): string => $document->getKey(), $documents));
}

/**
 * Runs the job like a worker does: through its own middleware, so the system context is entered.
 */
function archiveCleanupRun(string $archiveId): void
{
    $job = new BuildDocumentArchive($archiveId);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (BuildDocumentArchive $job): mixed => app()->call($job->handle(...)));
}

/**
 * Replaces the faked `local` disk with a partial mock whose put() forwards to the real disk and, for exactly that
 * path, calls $after once the bytes are written: the moment a record can change while a build runs.
 */
function archiveCleanupHookPut(string $path, Closure $after): void
{
    $real = Storage::disk('local');
    $hooked = Mockery::mock($real)->makePartial();

    $hooked->shouldReceive('put')->andReturnUsing(static function (mixed $target, mixed $contents, mixed $options = []) use ($real, $path, $after): mixed {
        $result = $real->put($target, $contents, $options);

        if ($target === $path) {
            $after();
        }

        return $result;
    });

    Storage::set('local', $hooked);
}

/**
 * Replaces the faked `local` disk with a partial mock whose delete() throws for the given path.
 */
function archiveCleanupFailDeleting(string $path): void
{
    $real = Storage::disk('local');
    $failing = Mockery::mock($real)->makePartial();

    $failing->shouldReceive('delete')->andReturnUsing(static function (mixed $target) use ($real, $path): bool {
        if ($target === $path) {
            throw new RuntimeException('Fictional disk failure.');
        }

        return $real->delete($target);
    });

    Storage::set('local', $failing);
}

/**
 * The bell entries of a user, as the number of rows.
 */
function archiveCleanupBellCount(User $user): int
{
    return DB::table('notifications')->where('notifiable_id', $user->getKey())->count();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('removes the ZIP an earlier attempt stored when the queue gives up on the job (WR-02)', function (): void {
    $archive = archiveCleanupPending([archiveCleanupFile('given-up-example.txt', 'Fictional given up')]);
    Storage::disk()->put($archive->storagePath(), 'Fictional bytes of an earlier attempt');

    (new BuildDocumentArchive($archive->getKey()))->failed(new RuntimeException('gave up'));

    expect(DocumentArchive::query()->findOrFail($archive->getKey())->status)->toBe(DocumentArchiveStatus::Failed)
        ->and(archiveCleanupBellCount($this->admin))->toBe(1)
        ->and(Storage::disk()->exists($archive->storagePath()))->toBeFalse();
});

it('removes the ZIP behind a record that no longer exists when the queue gives up (WR-02)', function (): void {
    $archiveId = (string) Str::uuid();
    $path = 'document-archives/'.$archiveId.'.zip';
    Storage::disk()->put($path, 'Fictional bytes of a pruned record');
    Storage::disk()->put('document-archives/another-object.zip', 'Fictional bystander');

    (new BuildDocumentArchive($archiveId))->failed(new RuntimeException('gave up'));

    expect(Storage::disk()->exists($path))->toBeFalse()
        ->and(Storage::disk()->exists('document-archives/another-object.zip'))->toBeTrue()
        ->and(archiveCleanupBellCount($this->admin))->toBe(0);
});

it('never turns an id that is not a UUID into a path (WR-02)', function (): void {
    Storage::disk()->put('document-archives/keep-me.zip', 'Fictional bystander');

    (new BuildDocumentArchive('keep-me'))->failed(new RuntimeException('gave up'));
    (new BuildDocumentArchive('../keep-me'))->failed(new RuntimeException('gave up'));

    expect(Storage::disk()->exists('document-archives/keep-me.zip'))->toBeTrue();
});

it('leaves a ready record and its ZIP alone when a late failure arrives (WR-02)', function (): void {
    $archive = archiveCleanupPending([archiveCleanupFile('late-example.txt', 'Fictional late')]);
    archiveCleanupRun($archive->getKey());

    $bells = archiveCleanupBellCount($this->admin);
    (new BuildDocumentArchive($archive->getKey()))->failed(new RuntimeException('late'));

    expect(DocumentArchive::query()->findOrFail($archive->getKey())->status)->toBe(DocumentArchiveStatus::Ready)
        ->and(Storage::disk()->exists($archive->storagePath()))->toBeTrue()
        ->and(archiveCleanupBellCount($this->admin))->toBe($bells);
});

it('removes the ZIP it just stored when the record is deleted during the build (WR-02)', function (): void {
    $archive = archiveCleanupPending([archiveCleanupFile('vanished-example.txt', 'Fictional vanished')]);
    $id = $archive->getKey();

    archiveCleanupHookPut($archive->storagePath(), static function () use ($id): void {
        DB::table('document_archives')->where('id', $id)->delete();
    });

    archiveCleanupRun($id);

    expect(DB::table('document_archives')->where('id', $id)->exists())->toBeFalse()
        ->and(Storage::disk()->exists($archive->storagePath()))->toBeFalse()
        ->and(archiveCleanupBellCount($this->admin))->toBe(0);
});

it('removes the ZIP it just stored when the record failed during the build (WR-02)', function (): void {
    $archive = archiveCleanupPending([archiveCleanupFile('failed-meanwhile-example.txt', 'Fictional failed')]);
    $id = $archive->getKey();

    archiveCleanupHookPut($archive->storagePath(), static function () use ($id): void {
        app(PartnerContext::class)->runAsSystem(static function () use ($id): void {
            DocumentArchive::query()->findOrFail($id)->markFailed();
        });
    });

    archiveCleanupRun($id);

    expect(DocumentArchive::query()->findOrFail($id)->status)->toBe(DocumentArchiveStatus::Failed)
        ->and(Storage::disk()->exists($archive->storagePath()))->toBeFalse()
        ->and(archiveCleanupBellCount($this->admin))->toBe(0);
});

it('keeps the ZIP when a concurrent run of the same archive finished it during the build (WR-02)', function (): void {
    $archive = archiveCleanupPending([archiveCleanupFile('finished-meanwhile-example.txt', 'Fictional finished')]);
    $id = $archive->getKey();

    archiveCleanupHookPut($archive->storagePath(), static function () use ($id): void {
        app(PartnerContext::class)->runAsSystem(static function () use ($id): void {
            $record = DocumentArchive::query()->findOrFail($id);
            $record->markReady($record->storagePath(), CarbonImmutable::now());
        });
    });

    archiveCleanupRun($id);

    expect(DocumentArchive::query()->findOrFail($id)->status)->toBe(DocumentArchiveStatus::Ready)
        ->and(Storage::disk()->exists($archive->storagePath()))->toBeTrue()
        ->and(Storage::disk()->get($archive->storagePath()))->toStartWith('PK')
        ->and(archiveCleanupBellCount($this->admin))->toBe(0);
});

it('still fails the record and sends the bell when the ZIP cannot be removed, and reports it without the path (WR-02)', function (): void {
    $archive = archiveCleanupPending([archiveCleanupFile('stuck-example.txt', 'Fictional stuck')]);
    Storage::disk()->put($archive->storagePath(), 'Fictional bytes of an earlier attempt');
    archiveCleanupFailDeleting($archive->storagePath());
    Exceptions::fake();

    (new BuildDocumentArchive($archive->getKey()))->failed(new RuntimeException('gave up'));

    expect(DocumentArchive::query()->findOrFail($archive->getKey())->status)->toBe(DocumentArchiveStatus::Failed)
        ->and(archiveCleanupBellCount($this->admin))->toBe(1);

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'could not be removed')
        && ! str_contains($e->getMessage(), 'document-archives/')
        && ! str_contains($e->getMessage(), $archive->getKey()));
});
