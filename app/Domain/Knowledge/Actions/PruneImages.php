<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The daily cleanup of knowledge images nobody needs (IMG-03, D-02, D-16, D-17). Only PruneKnowledgeImages calls it, in
 * the system context of a KokpitJob (the image model denies Partners and a worker has no user). It deletes nothing a
 * stored version still references and it never parses Markdown: every decision is SQL over the reference table, read
 * through the `references` relation of the image (D-16).
 *
 * Four passes, in this order:
 *  1. Pending: an image no save bound within PENDING_HOURS (`article_id` NULL, D-02) loses its object and its row.
 *  2. Clear: an image that carries an orphan stamp but is referenced again (a save or a restore indexed it) loses the
 *     stamp.
 *  3. Stamp: a bound image no stored version references gets `orphaned_at = now()`. An image removed from the body but
 *     still named by an earlier version is referenced and is never stamped (D-17).
 *  4. Delete: an image stamped more than ORPHAN_GRACE_DAYS ago loses its object and its row, after a recheck.
 *
 * Effective delays with a daily run: a pending image goes 24 to 25 hours after its upload, an orphan 7 to 8 days after
 * the last stored version stopped referencing it (the stamp is set by the first run that sees it).
 *
 * Concurrency (R-20-09-2). A save holds the article row and then the image rows it names `FOR UPDATE`, in id order,
 * while it binds and indexes (BindArticleImages). This cleanup locks one image row at a time and re-reads it under
 * that lock with the conditions of its pass, then rechecks the references. If a save indexed first, the re-read finds
 * the stamp cleared (or the recheck finds the reference) and the image stays; if the cleanup deleted first, the save's
 * locked read finds no row and skips the reference (D-18), so the save succeeds without it. Neither side fails. The
 * RESTRICT key of the reference table is the last line of defence: a referenced image cannot be deleted at all. The
 * stamp pass can at worst stamp an image whose reference commits in the same instant; the clear pass of the next run
 * and the recheck of pass 4 make that harmless.
 *
 * The object goes first, then the row, so an image whose object could not be deleted (an exception or a false result,
 * whatever the disk's `throw` setting) keeps its row and is retried by the next run. One such image never stops the
 * run: the other images and all four passes carry on, and `failures()` reports how many objects were refused so the job
 * can fail visibly afterwards. A missing object is no error. This Action deletes no reference row. Nothing is logged: the counts are
 * the only output.
 */
final class PruneImages
{
    public const int PENDING_HOURS = 24;

    public const int ORPHAN_GRACE_DAYS = 7;

    private const int CHUNK = 100;

    private int $failures = 0;

    /**
     * @return array{pending: int, stamped: int, cleared: int, deleted: int} the number of pending images deleted,
     *                                                                       images newly stamped, stamps cleared and orphans deleted
     */
    public function run(): array
    {
        $this->failures = 0;

        $pending = $this->prunePending();
        $cleared = $this->clearStamps();
        $stamped = $this->stampOrphans();
        $deleted = $this->deleteOrphans();

        return ['pending' => $pending, 'stamped' => $stamped, 'cleared' => $cleared, 'deleted' => $deleted];
    }

    /**
     * The number of images whose object the storage refused to delete in the last run(); their rows were kept.
     */
    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * Pass 1: deletes the pending images older than PENDING_HOURS, each in its own transaction.
     */
    private function prunePending(): int
    {
        $deleted = 0;

        KnowledgeArticleImage::query()
            ->whereNull('article_id')
            ->where('created_at', '<', now()->subHours(self::PENDING_HOURS))
            ->chunkById(self::CHUNK, function ($images) use (&$deleted): void {
                foreach ($images as $image) {
                    $deleted += $this->deletePending((string) $image->getKey()) ? 1 : 0;
                }
            });

        return $deleted;
    }

    /**
     * Deletes one pending image after re-reading it under a row lock: a save may have bound it meanwhile.
     */
    private function deletePending(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $image = KnowledgeArticleImage::query()
                ->whereKey($id)
                ->whereNull('article_id')
                ->lockForUpdate()
                ->first();

            if ($image === null) {
                return false;
            }

            // Object first: a row whose object could not be deleted stays and is retried by the next run.
            if (! $this->deleteObject($image->storagePath())) {
                return false;
            }

            $image->delete();

            return true;
        });
    }

    /**
     * Pass 2: an image stamped as an orphan that a stored version references again loses its stamp (D-16).
     */
    private function clearStamps(): int
    {
        return KnowledgeArticleImage::query()
            ->whereNotNull('orphaned_at')
            ->whereHas('references')
            ->update(['orphaned_at' => null]);
    }

    /**
     * Pass 3: a bound image no stored version references gets the orphan stamp (D-17).
     */
    private function stampOrphans(): int
    {
        return KnowledgeArticleImage::query()
            ->whereNotNull('article_id')
            ->whereNull('orphaned_at')
            ->whereDoesntHave('references')
            ->update(['orphaned_at' => now()]);
    }

    /**
     * Pass 4: deletes the orphans stamped more than ORPHAN_GRACE_DAYS ago, each in its own transaction.
     */
    private function deleteOrphans(): int
    {
        $deleted = 0;
        $cutoff = now()->subDays(self::ORPHAN_GRACE_DAYS);

        KnowledgeArticleImage::query()
            ->whereNotNull('orphaned_at')
            ->where('orphaned_at', '<', $cutoff)
            ->chunkById(self::CHUNK, function ($images) use (&$deleted, $cutoff): void {
                foreach ($images as $image) {
                    $deleted += $this->deleteOrphan((string) $image->getKey(), $cutoff) ? 1 : 0;
                }
            });

        return $deleted;
    }

    /**
     * Deletes one orphan after re-reading it under a row lock and rechecking its references. A reference that appeared
     * meanwhile clears the stamp instead.
     *
     * @param  CarbonInterface  $cutoff  the newest stamp time that may still be deleted
     */
    private function deleteOrphan(string $id, CarbonInterface $cutoff): bool
    {
        return DB::transaction(function () use ($id, $cutoff): bool {
            $image = KnowledgeArticleImage::query()
                ->whereKey($id)
                ->whereNotNull('orphaned_at')
                ->where('orphaned_at', '<', $cutoff)
                ->lockForUpdate()
                ->first();

            if ($image === null) {
                return false;
            }

            if ($image->references()->exists()) {
                $image->forceFill(['orphaned_at' => null])->save();

                return false;
            }

            if (! $this->deleteObject($image->storagePath())) {
                return false;
            }

            $image->delete();

            return true;
        });
    }

    /**
     * Deletes the object; a refusal in any form (an exception, a false result) is counted, never thrown, and the
     * caller keeps the row. The object key holds no user text and neither does the swallowed exception.
     */
    private function deleteObject(string $path): bool
    {
        try {
            $deleted = Storage::disk()->delete($path) !== false;
        } catch (Throwable) {
            $deleted = false;
        }

        if (! $deleted) {
            $this->failures++;
        }

        return $deleted;
    }
}
