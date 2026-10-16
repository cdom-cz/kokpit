<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes every document archive past its expiry: the ZIP on the private disk first, then the record (DO-02, D-08).
 * Scheduled daily on one server. The twin of PruneExpiredReportExports.
 *
 * The expiry is 7 days after completion for a finished archive and 7 days after creation for a pending or failed
 * one, so a record that never became a file is removed on schedule as well.
 *
 * Runs as system (KokpitJob), so the owner scope shows the archives of every Admin. The object path is
 * `DocumentArchive::storagePath()`, a pure function of the record id and the shape the database pins for `path`
 * (`document-archives/<uuid>.zip`), so it is deleted whatever the status of the record: a failed or raced build can
 * leave a ZIP although no path was recorded (WR-02). A missing object is not an error. Nothing outside
 * `document-archives/<uuid>.zip` of an expired record is reachable. Records are read in chunks by id, so a large
 * backlog is never loaded at once.
 */
#[Idempotent(how: 'deletes the id-derived object and the row of every archive past expires_at; a second run finds nothing to delete and a missing object is skipped')]
final class PruneExpiredDocumentArchives extends KokpitJob
{
    private const int CHUNK = 100;

    public function handle(): void
    {
        $disk = Storage::disk();

        DocumentArchive::query()
            ->where('expires_at', '<', now())
            ->chunkById(self::CHUNK, static function ($archives) use ($disk): void {
                foreach ($archives as $archive) {
                    // Object first: a record whose object could not be deleted stays and is retried by the next run.
                    $disk->delete($archive->storagePath());

                    $archive->delete();
                }
            });
    }
}
