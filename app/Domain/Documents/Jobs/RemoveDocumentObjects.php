<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Shared\Models\Media;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Removes the stored objects of deleted documents that the disk refused right after the commit (DO-03, WR-01).
 *
 * {@see DeleteDocument} deletes the rows in the database transaction and the objects
 * only after the commit, so a refused object can no longer undo a delete the actor confirmed. This job delivers
 * the guarantee of the owner decision D-23 for DO-03: the object is retried 3 times by the KokpitJob defaults
 * (backoff 10, 60 and 300 s), and when every try fails the failed-job alert (ReportFailedJob) reaches the Admin.
 * A refused object is therefore never left behind silently.
 *
 * It deletes by path on the private bucket, so a payload is never trusted. An entry is skipped (and reported
 * without its path) unless all three guards hold: the disk is the configured media disk, the path has exactly the
 * shape of a document object (optional media prefix, media uuid, document uuid with an optional plain extension),
 * and no media row uses the media uuid any more. A skipped entry is not an error, so the queue does not retry it.
 * While an object that passed the guards still stands after one strict delete, the job throws a message without
 * any path and the queue retries.
 */
#[Idempotent(how: 'identified by the listed disk and path pairs; an object already gone is skipped and a path a media row still uses or that is not a document object path is never touched, so a rerun changes nothing')]
final class RemoveDocumentObjects extends KokpitJob
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * The objects to remove, as `list<array{disk: string, path: string}>`. The payload comes back from the queue,
     * so it is typed loosely and every entry is checked at run time.
     *
     * @param  array<array-key, mixed>  $objects
     */
    public function __construct(public readonly array $objects) {}

    public function handle(): void
    {
        $mediaDisk = (string) config('media-library.disk_name');
        $standing = false;

        foreach ($this->objects as $object) {
            $disk = is_array($object) ? ($object['disk'] ?? null) : null;
            $path = is_array($object) ? ($object['path'] ?? null) : null;

            if (! is_string($disk) || ! is_string($path)) {
                $this->skipped('malformed entry');

                continue;
            }

            if ($disk !== $mediaDisk) {
                $this->skipped('not the media disk');

                continue;
            }

            $mediaId = $this->mediaIdOf($path);

            if ($mediaId === null) {
                $this->skipped('not a document object path');

                continue;
            }

            if (Media::query()->whereKey($mediaId)->exists()) {
                $this->skipped('a media row still uses it');

                continue;
            }

            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }

                $standing = $standing || Storage::disk($disk)->exists($path);
            } catch (Throwable) {
                $standing = true;
            }
        }

        if ($standing) {
            // No path in the message: the exception reaches the failed-jobs table and the Admin alert.
            throw new RuntimeException('A stored object of a deleted document could not be removed.');
        }
    }

    /**
     * The media uuid of a path of the document object shape, or null for any other path.
     */
    private function mediaIdOf(string $path): ?string
    {
        $prefix = trim((string) config('media-library.prefix'), '/');
        $head = $prefix === '' ? '' : preg_quote($prefix, '#').'/';

        if (preg_match('#^'.$head.'('.self::UUID.')/'.self::UUID.'(\.[A-Za-z0-9]{1,10})?$#D', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Reports why an entry was left untouched, with the reason only and never the path.
     */
    private function skipped(string $reason): void
    {
        report(new RuntimeException('A queued removal of a document object was skipped ('.$reason.').'));
    }
}
