<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Jobs\RemoveDocumentObjects;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Throwable;

/**
 * Permanently deletes a file: the documents row, its media row and the stored object (DO-03, D-09, D-23).
 *
 * The deletion is one-way by the owner's decision (no trash, nothing to restore), so the caller asks for a
 * confirmation before it gets here. The actor is checked against the `delete` ability first; the work then
 * runs as system, because `Media` is closed to Partners and the media rows would otherwise be invisible in a
 * Partner's context (research Pitfall 3).
 *
 * The database comes first and the irreversible side effect last (09-REVIEW.md WR-01, which supersedes the
 * order of plan 09-07). Inside one transaction the row is re-read with `lockForUpdate()`, its media rows are
 * deleted with a query-builder delete (no model event, so the medialibrary observer cannot remove the file
 * now) and the document is deleted with `deletePreservingMedia()`; the allowlisted activity row of the deleted
 * event is written in the same transaction. The stored objects are removed by a `DB::afterCommit()` callback,
 * which Laravel runs only after the outermost enclosing transaction commits and drops when any enclosing
 * transaction rolls back. A rollback anywhere (a later receipt, the transaction row, the commit itself)
 * therefore never costs an object: row, media row and object all stay.
 *
 * That timing deviates from D-09 ("together with the row") by the owner decision D-23: the object is removed
 * after the commit; an object the disk then refuses is reported and handed to {@see RemoveDocumentObjects}
 * (3 tries with backoff), and when every try fails the failed-job alert reaches the Admin. A refused object can
 * no longer undo or block a delete the actor confirmed.
 *
 * The after-commit callback may run outside the system context, so it queries no model: it works on the media
 * instances read inside the transaction and on the disk only, and it never throws.
 */
final class DeleteDocument
{
    public function __construct(private readonly PartnerContext $context) {}

    /**
     * @throws RuntimeException when the delete is refused or the database fails; nothing is deleted then
     */
    public function handle(User $actor, Document $document): void
    {
        Gate::forUser($actor)->authorize('delete', $document);

        $this->context->runAsSystem(function () use ($document): void {
            DB::transaction(function () use ($document): void {
                $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                $media = Media::query()
                    ->where('model_type', $locked->getMorphClass())
                    ->where('model_id', $locked->getKey())
                    ->get();

                $objects = [];

                foreach ($media as $item) {
                    $objects[] = ['disk' => $item->disk, 'path' => $item->getPathRelativeToRoot()];
                }

                if ($media->isNotEmpty()) {
                    // A query-builder delete fires no model event: the package observer must not remove the file yet.
                    Media::query()->whereKey($media->modelKeys())->delete();
                }

                $locked->deletePreservingMedia();

                DB::afterCommit(fn () => $this->removeObjects(array_values($media->all()), $objects));
            });
        });
    }

    /**
     * Removes the objects of a deleted document after the commit. Queries no model and never throws: the
     * delete is already committed, so a failure here is reported and left to the retry, not undone.
     *
     * @param  list<Media>  $media
     * @param  list<array{disk: string, path: string}>  $objects
     */
    private function removeObjects(array $media, array $objects): void
    {
        // The package remover (conversions and the empty directory included) swallows and reports a failing disk.
        foreach ($media as $item) {
            try {
                app(Filesystem::class)->removeAllFiles($item);
            } catch (Throwable) {
                // The strict check below finds what is left.
            }
        }

        $standing = [];
        $cause = null;

        foreach ($objects as $object) {
            try {
                if ($this->exists($object)) {
                    // The package remover went on after a failure; try once more where the error is not swallowed.
                    Storage::disk($object['disk'])->delete($object['path']);
                }

                if ($this->exists($object)) {
                    $standing[] = $object;
                }
            } catch (Throwable $e) {
                $cause = $e;
                $standing[] = $object;
            }
        }

        if ($standing === []) {
            return;
        }

        // No name and no path in the message: the exception can reach a log or a screen.
        report(new RuntimeException('A stored object of a deleted document could not be removed ('.($cause === null ? 'the disk kept it' : $cause::class).').'));

        // The retry job removes exactly the objects left, 3 tries, then the failed-job alert to the Admin (D-23).
        try {
            RemoveDocumentObjects::dispatch($standing);
        } catch (Throwable $e) {
            report(new RuntimeException('The removal of a stored object of a deleted document could not be queued ('.$e::class.').'));
        }
    }

    /**
     * Reads the disk every time: the answer changes when an object is deleted between two calls.
     *
     * @param  array{disk: string, path: string}  $object
     *
     * @phpstan-impure
     */
    private function exists(array $object): bool
    {
        return Storage::disk($object['disk'])->exists($object['path']);
    }
}
