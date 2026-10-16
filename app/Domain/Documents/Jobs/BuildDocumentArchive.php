<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\TimeTracking\Support\TimerClock;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
 * The files go in as the requested ids list them, under the display name of the document. A document deleted
 * after the request is left out; a request none of whose files can be read fails. Safe entry names, the second
 * check of the limits, failure handling and the bells are plan 09-19.
 */
#[Idempotent(how: 'identified by the archive id; a record that is not pending is skipped and the file path is the archive id, so a rerun overwrites the same object instead of adding another')]
#[Timeout(240)]
final class BuildDocumentArchive extends KokpitJob
{
    /** How many documents are read from the database at once. */
    private const int CHUNK = 50;

    public function __construct(public readonly string $archiveId) {}

    public function handle(): void
    {
        $archive = DocumentArchive::query()->find($this->archiveId);

        // Pruned in the meantime, failed, or already built by an earlier run: nothing to do.
        if ($archive === null || $archive->status !== DocumentArchiveStatus::Pending) {
            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'kokpit-zip-');

        if ($temporary === false) {
            throw new RuntimeException('A temporary file for the archive could not be created.');
        }

        try {
            $this->write($archive, $temporary);
            $this->store($archive, $temporary);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        DB::transaction(function () use ($archive): void {
            $locked = DocumentArchive::query()->whereKey($archive->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== DocumentArchiveStatus::Pending) {
                return;
            }

            $locked->markReady($locked->storagePath(), TimerClock::now());
        });
    }

    /**
     * Streams the requested documents into the ZIP file, in the order of the request.
     *
     * @throws RuntimeException when the file cannot be opened or no document could be added
     */
    private function write(DocumentArchive $archive, string $file): void
    {
        $output = fopen($file, 'wb');

        if ($output === false) {
            throw new RuntimeException('The archive file could not be opened.');
        }

        $added = 0;

        try {
            $zip = new ZipStream(outputStream: $output, sendHttpHeaders: false);

            foreach (array_chunk($archive->document_ids, self::CHUNK) as $ids) {
                $documents = Document::query()->whereIn('documents.id', $ids)->get()->keyBy('id');

                foreach ($ids as $id) {
                    $document = $documents->get($id);

                    if ($document instanceof Document) {
                        $this->add($zip, $document);
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
    }

    /**
     * Adds the stored object of one document under its display name.
     *
     * @throws RuntimeException when the document has no stored object or it cannot be read
     */
    private function add(ZipStream $zip, Document $document): void
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
            $zip->addFileFromStream(fileName: $document->name, stream: $stream);
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
