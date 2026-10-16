<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Permanently deletes a file: the documents row, its media row and the stored object (DO-03, D-09).
 *
 * The deletion is one-way by the owner's decision (no trash, nothing to restore), so the caller asks for a
 * confirmation before it gets here. The actor is checked against the `delete` ability first; the work then
 * runs as system, because `Media` is closed to Partners and the package hook that removes the media would
 * otherwise find no rows in a Partner's context and leave the object behind (research Pitfall 3).
 *
 * Inside one transaction the row is re-read with `lockForUpdate()` and deleted through Eloquent, so the
 * medialibrary hook removes the media row and its observer the object, and the allowlisted activity row
 * of the deleted event is written. The package's file remover swallows (and reports) a failing disk, so
 * the Action does not trust it: it checks that every object is gone, makes one strict attempt where one is
 * left, and throws when it still stands. The transaction then rolls back and the row, the media row and
 * the activity record stay as they were (research Pitfall 4). The rare orphan left by a commit that fails
 * after the object was removed is found by the consistency check of plan 09-20.
 */
final class DeleteDocument
{
    public function __construct(private readonly PartnerContext $context) {}

    /**
     * @throws RuntimeException when a stored object cannot be removed; nothing is deleted then
     */
    public function handle(User $actor, Document $document): void
    {
        Gate::forUser($actor)->authorize('delete', $document);

        $this->context->runAsSystem(function () use ($document): void {
            DB::transaction(function () use ($document): void {
                $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                $objects = $this->objectsOf($locked);

                $locked->delete();

                $this->assertObjectsRemoved($objects);
            });
        });
    }

    /**
     * Where the objects of a document live, read before the media rows go.
     *
     * @return list<array{disk: string, path: string}>
     */
    private function objectsOf(Document $document): array
    {
        $objects = [];

        foreach (Media::query()->where('model_type', $document->getMorphClass())->where('model_id', $document->getKey())->get() as $media) {
            $objects[] = ['disk' => $media->disk, 'path' => $media->getPathRelativeToRoot()];
        }

        return $objects;
    }

    /**
     * @param  list<array{disk: string, path: string}>  $objects
     *
     * @throws RuntimeException when an object is still there after a strict delete
     */
    private function assertObjectsRemoved(array $objects): void
    {
        foreach ($objects as $object) {
            if (! $this->exists($object)) {
                continue;
            }

            // The package remover reported its failure and went on; try once more where the error is not swallowed.
            Storage::disk($object['disk'])->delete($object['path']);

            if ($this->exists($object)) {
                // No path in the message: the exception can reach a log or a screen.
                throw new RuntimeException('The stored object of a document could not be removed.');
            }
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
