<?php

declare(strict_types=1);

namespace App\Domain\Reports\Jobs;

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Reports\Models\ReportExport;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes every report export past its expiry: the file on the private disk first, then the record (RE-03, D-09).
 * Scheduled daily on one server.
 *
 * Runs as system (KokpitJob), so the owner scope shows the exports of every Admin. Only the stored `path` of an
 * expired record is deleted, and the database pins that path to `report-exports/<uuid>.<csv|xlsx>`, so no other
 * file of the disk can be reached. A pending or failed record has no path and a ready one may have lost its file;
 * both are deleted without an error. Records are read in chunks by id, so a large backlog is never loaded at once.
 */
#[Idempotent(how: 'deletes the file and the row of every export past expires_at; a second run finds nothing to delete and a missing file is skipped')]
final class PruneExpiredReportExports extends KokpitJob
{
    private const int CHUNK = 100;

    public function handle(): void
    {
        $disk = Storage::disk();

        ReportExport::query()
            ->where('expires_at', '<', now())
            ->chunkById(self::CHUNK, static function ($exports) use ($disk): void {
                foreach ($exports as $export) {
                    // File first: a record whose file could not be deleted stays and is retried by the next run.
                    if ($export->path !== null) {
                        $disk->delete($export->path);
                    }

                    $export->delete();
                }
            });
    }
}
