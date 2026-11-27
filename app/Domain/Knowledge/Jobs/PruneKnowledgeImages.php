<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Jobs;

use App\Domain\Knowledge\Actions\PruneImages;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use RuntimeException;

/**
 * Deletes the knowledge images nobody needs (IMG-03, D-02, D-16, D-17): every pending upload that no save bound within
 * 24 hours, and every image that no stored version references any more, 7 days after it was stamped as an orphan.
 * Scheduled daily on one server; the work itself is `PruneImages::run()`.
 *
 * With a daily run the effective delays are 24 to 25 hours for a pending image and 7 to 8 days for an orphan: the first
 * run that sees an unreferenced image stamps it, and the first run more than 7 days after that stamp deletes it. An
 * image that any stored version still references is never stamped and never deleted, however old it is.
 *
 * Runs as system (KokpitJob): the image model denies Partners and a worker has no user. The decision is SQL over the
 * reference table and is rechecked under a row lock, so a save that references the same image at the same moment can
 * neither lose the image nor fail.
 */
#[Idempotent(how: 'deletes id-derived objects and their rows only after rechecking them under a row lock; a second run finds nothing to delete and a missing object is skipped')]
final class PruneKnowledgeImages extends KokpitJob
{
    public function handle(PruneImages $prune): void
    {
        $prune->run();

        // Every pass has run and the refused objects keep their rows for the next run; the failure is raised only now,
        // so one stuck object never blocks the cleanup of the others but is still visible in the failed jobs.
        if ($prune->failures() > 0) {
            throw new RuntimeException("Knowledge image cleanup could not delete {$prune->failures()} stored object(s).");
        }
    }
}
