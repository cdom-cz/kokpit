<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Notifications\DocumentArchiveFailedNotification;
use App\Domain\Documents\Notifications\DocumentArchiveReadyNotification;
use App\Domain\Documents\Support\ArchiveEntryNames;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Resources\DocumentArchiveResource;
use App\Providers\LocalisationServiceProvider;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipStream\ZipStream;

/**
 * Builds the ZIP of a document archive request in the background (DO-02, D-08).
 *
 * The twin of BuildReportExport. The record is read in the system context (the job middleware) and skipped when
 * it is gone or no longer pending. The archive is written by streaming each stored object from its media disk
 * into a temporary file through ZipStream, so memory does not grow with the number or size of the files
 * (research Pattern 5); the finished file is then streamed to the default (private) disk under the
 * server-generated path of the record, and only after that the record is locked, checked once more and marked
 * ready in one transaction. The temporary file is removed whatever happens. The timeout stays under the 300 s of
 * the Horizon supervisor.
 *
 * The limits of the request (file count and summed size) are read again from the database before anything is
 * built: the configuration may have been lowered and the files changed since. An archive that exceeds them, or
 * none of whose documents still exists, is marked failed at once and the Admin is told in the bell; retrying
 * cannot help. The files go in as the requested ids list them, a document deleted after the request is left out
 * and only the included files are counted. Every entry name is the display name of the document passed through
 * {@see ArchiveEntryNames}: no separator, no parent segment, and equal names are numbered instead of overwritten.
 *
 * The Admin who asked gets one bell when the archive is ready (with the count and the expiry day) or failed,
 * both with a link to the archives list; the ready bell is sent in the transaction that marks the record ready.
 * A technical failure (a stored object that cannot be read) is thrown, retried by the queue and, after the last
 * attempt, handled by {@see self::failed()}.
 *
 * The ZIP never outlives a record that failed or vanished (WR-02): when the queue gives up, or the final transaction
 * finds the record gone or failed, the object at the path of the record id is removed after that transaction
 * commits, so the failed state is stored before the object goes. The ZIP of a ready record is never touched: the
 * path is shared by every run of the same id, so it is the finished archive of a concurrent run. A removal that
 * fails is reported without the path and swallowed, the failure handling completes, and the prune removes the
 * object later.
 */
#[Idempotent(how: 'identified by the archive id; a record that is not pending is skipped and the file path is the archive id, so a rerun overwrites the same object instead of adding another')]
#[Timeout(240)]
final class BuildDocumentArchive extends KokpitJob
{
    /** How many documents are read from the database at once. */
    private const int CHUNK = 50;

    private const int DEFAULT_MAX_FILES = 200;

    private const int DEFAULT_MAX_BYTES = 209715200;

    /** The time zone the dates of the notices are shown in. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    public function __construct(public readonly string $archiveId) {}

    public function handle(): void
    {
        $archive = DocumentArchive::query()->find($this->archiveId);

        // Pruned in the meantime, failed, or already built by an earlier run: nothing to do.
        if ($archive === null || $archive->status !== DocumentArchiveStatus::Pending) {
            return;
        }

        // The limits of the request are checked again: the configuration may have been lowered, the files may have
        // grown or been deleted since. Retrying cannot help, so the archive fails at once instead of throwing.
        if (! $this->withinLimits($archive)) {
            $this->giveUp($archive->getKey());

            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'kokpit-zip-');

        if ($temporary === false) {
            throw new RuntimeException('A temporary file for the archive could not be created.');
        }

        try {
            $added = $this->write($archive, $temporary);
            $this->store($archive, $temporary);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        DB::transaction(function () use ($archive, $added): void {
            $locked = DocumentArchive::query()->whereKey($archive->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status === DocumentArchiveStatus::Failed) {
                // Gone or failed while the build ran: nobody will ever offer this ZIP.
                $this->removeZipAfterCommit($archive->storagePath());

                return;
            }

            // Ready: a concurrent run of the same id finished, and the object at this shared path is its ZIP.
            if ($locked->status !== DocumentArchiveStatus::Pending) {
                return;
            }

            $locked->markReady($locked->storagePath(), TimerClock::now());

            User::query()->find($locked->user_id)?->notifyNow(new DocumentArchiveReadyNotification(
                count: $added,
                expiresOn: $locked->expires_at->setTimezone(self::DISPLAY_TIMEZONE)->format(LocalisationServiceProvider::DATE_FORMAT),
                url: DocumentArchiveResource::getUrl('index'),
            ));
        });
    }

    /**
     * The build failed for good: the record is marked failed and the owner is told in the bell. A record that is
     * gone or no longer pending is left alone. The job middleware does not wrap this method, so the system
     * context is entered here.
     */
    public function failed(Throwable $exception): void
    {
        app(PartnerContext::class)->runAsSystem(function (): void {
            $this->giveUp($this->archiveId);
        });
    }

    /**
     * Marks a still pending record failed and sends the failure bell in one transaction, then removes the ZIP an
     * earlier attempt may have stored once that transaction commits. A record that is gone or already failed only
     * loses its ZIP; a ready one is left alone with its object.
     */
    private function giveUp(string $archiveId): void
    {
        // The id comes from the queue payload: a value that is no UUID is no record (the uuid column would refuse
        // the lookup) and never becomes part of a path.
        if (! Str::isUuid($archiveId)) {
            return;
        }

        DB::transaction(function () use ($archiveId): void {
            $locked = DocumentArchive::query()->whereKey($archiveId)->lockForUpdate()->first();

            if ($locked === null) {
                // Built through a model carrying the id, so storagePath() stays the one place that knows the shape.
                $probe = new DocumentArchive;
                $probe->setAttribute($probe->getKeyName(), $archiveId);
                $this->removeZipAfterCommit($probe->storagePath());

                return;
            }

            if ($locked->status === DocumentArchiveStatus::Failed) {
                $this->removeZipAfterCommit($locked->storagePath());

                return;
            }

            if ($locked->status !== DocumentArchiveStatus::Pending) {
                return;
            }

            $locked->markFailed();

            User::query()->find($locked->user_id)?->notifyNow(new DocumentArchiveFailedNotification(DocumentArchiveResource::getUrl('index')));

            $this->removeZipAfterCommit($locked->storagePath());
        });
    }

    /**
     * Deletes the ZIP at the path on the default disk once the surrounding transaction has committed. A failing
     * removal is reported with the exception class only (the path names the archive) and never rethrown: the
     * failure handling must complete, and the daily prune removes the object later.
     */
    private function removeZipAfterCommit(string $path): void
    {
        DB::afterCommit(static function () use ($path): void {
            try {
                Storage::disk()->delete($path);
            } catch (Throwable $e) {
                report(new RuntimeException('The ZIP of a document archive could not be removed ('.$e::class.').'));
            }
        });
    }

    /**
     * Whether the documents that still exist can go into one archive: at least one is left, and their number and
     * summed size are within `kokpit.documents.archive.max_files` and `kokpit.documents.archive.max_bytes`. The
     * numbers come from the database, not from the record, because the record was written at the request.
     */
    private function withinLimits(DocumentArchive $archive): bool
    {
        $maxFiles = max(1, (int) config('kokpit.documents.archive.max_files', self::DEFAULT_MAX_FILES));
        $maxBytes = max(1, (int) config('kokpit.documents.archive.max_bytes', self::DEFAULT_MAX_BYTES));

        $files = 0;
        $bytes = 0;

        foreach (array_chunk($archive->document_ids, self::CHUNK) as $ids) {
            $totals = Document::query()
                ->selectRaw('COUNT(*) AS files, COALESCE(SUM(documents.size_bytes), 0) AS bytes')
                ->whereIn('documents.id', $ids)
                ->toBase()
                ->first();

            $files += (int) ($totals->files ?? 0);
            $bytes += (int) ($totals->bytes ?? 0);
        }

        return $files > 0 && $files <= $maxFiles && $bytes <= $maxBytes;
    }

    /**
     * Streams the requested documents into the ZIP file, in the order of the request, and returns how many were
     * added.
     *
     * @throws RuntimeException when the file cannot be opened or no document could be added
     */
    private function write(DocumentArchive $archive, string $file): int
    {
        $output = fopen($file, 'wb');

        if ($output === false) {
            throw new RuntimeException('The archive file could not be opened.');
        }

        $added = 0;
        $names = new ArchiveEntryNames;

        try {
            $zip = new ZipStream(outputStream: $output, sendHttpHeaders: false);

            foreach (array_chunk($archive->document_ids, self::CHUNK) as $ids) {
                $documents = Document::query()->whereIn('documents.id', $ids)->get()->keyBy('id');

                foreach ($ids as $id) {
                    $document = $documents->get($id);

                    if ($document instanceof Document) {
                        $this->add($zip, $document, $names);
                        $added++;
                    }
                }
            }

            if ($added === 0) {
                throw new RuntimeException('None of the files of the archive could be found.');
            }

            $zip->finish();
        } finally {
            fclose($output);
        }

        return $added;
    }

    /**
     * Adds the stored object of one document under a safe, unique entry name made of its display name.
     *
     * @throws RuntimeException when the document has no stored object or it cannot be read
     */
    private function add(ZipStream $zip, Document $document, ArchiveEntryNames $names): void
    {
        $media = $document->getFirstMedia(Document::MEDIA_COLLECTION);

        if ($media === null) {
            throw new RuntimeException('A file of the archive has no stored object.');
        }

        $stream = Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot());

        if (! is_resource($stream)) {
            // No name in the message: the exception can reach a log or a screen.
            throw new RuntimeException('A stored object of the archive could not be read.');
        }

        try {
            $zip->addFileFromStream(fileName: $names->unique(ArchiveEntryNames::safe($document->name, $document->extension)), stream: $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Streams the finished file to the default (private) disk under the server-generated path of the record.
     * An earlier attempt that died after writing leaves the same path, which is overwritten.
     */
    private function store(DocumentArchive $archive, string $file): void
    {
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The archive file could not be read back.');
        }

        try {
            $stored = Storage::disk()->put($archive->storagePath(), $stream);
        } finally {
            fclose($stream);
        }

        if ($stored === false) {
            throw new RuntimeException('The archive file could not be stored.');
        }
    }
}
