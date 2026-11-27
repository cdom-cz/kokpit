<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Images\ImageNames;
use App\Domain\Knowledge\Images\ImagePipeline;
use App\Domain\Knowledge\Images\ImageRefusal;
use App\Domain\Knowledge\Images\ImageRefused;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Support\ImageReferences;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use PDOException;
use Throwable;

/**
 * Turns one uploaded file into a stored, pending knowledge image and the reference to insert into the body (IMG-01,
 * IMG-02, D-01, D-02, D-03, D-08). The upload goes through {@see ImagePipeline}; only the bytes GD encoded itself are
 * stored, privately on the default disk at `knowledge/images/<uuid>.<ext>`, and the row stays pending (no article)
 * until a save binds it (D-02).
 *
 * The file name and the MIME type the client declared never decide anything (D-07); the type is decided by the
 * content alone. Nothing is logged: the file name is attacker-controlled text.
 *
 * The object is written first and the row second, so a row never exists without its object; a failed row write
 * removes the object again.
 */
final class StoreArticleImage
{
    public function __construct(private readonly ImagePipeline $pipeline) {}

    /**
     * @throws AuthorizationException when the actor may not create images (a Partner)
     * @throws ImageRefused when the file is refused; nothing was stored
     * @throws ArticleWriteFailed when the database refused the row; the object was removed again
     * @throws FilesystemException when the storage refused the object
     */
    public function handle(User $actor, UploadedFile $file): StoredImage
    {
        Gate::forUser($actor)->authorize('create', KnowledgeArticleImage::class);

        $bytes = $this->read($file);

        $processed = $this->pipeline->process($bytes);

        // The original upload is not kept past this point.
        unset($bytes);

        $id = strtolower((string) Str::uuid7());

        $image = new KnowledgeArticleImage;
        $image->forceFill([
            'id' => $id,
            'article_id' => null,
            'uploaded_by' => $actor->getKey(),
            'file_name' => ImageNames::fileName($file->getClientOriginalName(), $processed->format),
            'mime_type' => $processed->mimeType(),
            'width' => $processed->width,
            'height' => $processed->height,
            'size_bytes' => $processed->size(),
            'sha256' => $processed->sha256,
        ]);

        $path = $image->storagePath();

        if (Storage::disk()->put($path, $processed->bytes) === false) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        try {
            $image->save();
        } catch (PDOException $e) {
            $this->removeObject($path);

            // The original exception is not chained: its message holds the bound values, among them the file name.
            throw new ArticleWriteFailed((string) $e->getCode());
        }

        $alt = ImageNames::altText($file->getClientOriginalName());

        return new StoredImage(
            id: $id,
            altText: $alt,
            width: $processed->width,
            height: $processed->height,
            reference: ImageReferences::markdown($id, $alt),
        );
    }

    /**
     * The bytes of the upload, after a size check that does not read them. A read that fails or yields nothing is a
     * failure to process, not a statement about the file.
     *
     * @throws ImageRefused
     */
    private function read(UploadedFile $file): string
    {
        try {
            $size = $file->getSize();
        } catch (Throwable) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        if ($size > ImagePipeline::MAX_UPLOAD_BYTES) {
            throw new ImageRefused(ImageRefusal::TooLarge);
        }

        try {
            $bytes = $file->get();
        } catch (Throwable) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        // An empty file is the pipeline's to refuse (NotAnImage); a read that returned no string at all is a failure.
        if (! is_string($bytes)) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        return $bytes;
    }

    /**
     * Best effort: a failure here never masks the exception that caused the cleanup.
     */
    private function removeObject(string $path): void
    {
        try {
            Storage::disk()->delete($path);
        } catch (Throwable) {
            // The object key holds no user text; an orphaned object is the lesser harm.
        }
    }
}
