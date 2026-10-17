<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Stores bytes the server generated itself, such as the PDF of an issued invoice, as a document through the one
 * hardened upload path (D-21, D-23): the bytes go to a temporary file, the file is wrapped as an upload and handed to
 * {@see AttachDocuments}, so the authorisation, the owner resolution, the content-based MIME detection, the size limit
 * and the object naming are exactly those of a user upload. The file name is chosen by the caller from trusted parts
 * only.
 *
 * The caller owns a database transaction and may roll it back after the upload: the object lives in the bucket, which
 * no rollback reaches. A callback registered with `DB::afterRollBack()` before the upload removes the written objects
 * when the transaction that is open at the call rolls back, so a failed issue leaves no orphaned object (research
 * Pitfall 4, D-23). `AttachDocuments` already removes its objects when it fails by itself. The temporary file is
 * removed in every case.
 */
final readonly class StoreGeneratedDocument
{
    public function __construct(private AttachDocuments $attach) {}

    /**
     * @throws ValidationException when the generated file is refused by the upload limits
     * @throws RuntimeException when the temporary file cannot be written
     */
    public function handle(User $actor, Model $owner, string $bytes, string $fileName, string $mimeType = 'application/pdf'): Document
    {
        $temporary = tempnam(sys_get_temp_dir(), 'kokpit-generated-');

        if ($temporary === false) {
            throw new RuntimeException('A temporary file for the generated document could not be created.');
        }

        try {
            if (file_put_contents($temporary, $bytes) === false) {
                throw new RuntimeException('The generated document could not be written to a temporary file.');
            }

            $upload = new UploadedFile($temporary, $fileName, $mimeType, null, true);

            $document = $this->attach->handle($actor, $owner, [$upload], false)->first()
                ?? throw new RuntimeException('The generated document was not stored.');

            return $document;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
