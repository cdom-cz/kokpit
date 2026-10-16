<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentOwnerResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The single upload entry point for files (DO-01, D-01, D-03, D-20).
 *
 * The actor needs the `attach` ability on documents for the given owner (the Admin through the policy's
 * `before()`; a Partner is turned away until a later plan grants it). Everything that decides what a file
 * hangs on comes from the server: DocumentOwnerResolver derives the owner pair, the client and the project,
 * and nothing but the client's original file name (as the display name) and the description comes from the
 * request. The stored object is named `<document uuid>.<extension>` and never contains user text.
 *
 * Visibility: the caller's wish, or the owner's default when null, applies only to an owner that can be
 * shared (project, task, a non-internal comment); a client file, an unattached file and an internal comment's
 * file are stored hidden even when the caller asks for visible. The database CHECK is the backstop.
 *
 * The MIME type is detected from the file content, never taken from the client. The media write runs as
 * system after the policy passed, because `Media` stays closed to Partners. One database transaction covers
 * all files of a call. Strict input validation (limits, forged state, names, all-or-nothing storage) follows
 * in plan 09-02.
 */
final class AttachDocuments
{
    private const int MAX_NAME_LENGTH = 255;

    public function __construct(
        private readonly DocumentOwnerResolver $resolver,
        private readonly PartnerContext $context,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, Document>
     */
    public function handle(User $actor, ?Model $owner, array $files, ?bool $visibleToClient = null, ?string $description = null): Collection
    {
        Gate::forUser($actor)->authorize('attach', [Document::class, $owner]);

        /** @var Collection<int, Document> */
        return DB::transaction(function () use ($actor, $owner, $files, $visibleToClient, $description): Collection {
            $resolved = $this->resolver->resolve($owner);
            $visible = $resolved['shareable'] && ($visibleToClient ?? $resolved['default_visible']);

            $documents = new Collection;

            foreach ($files as $file) {
                $documents->push($this->store($actor, $resolved, $visible, $file, $description));
            }

            return $documents;
        });
    }

    /**
     * @param  array{owner_type: ?string, owner_id: ?string, client_id: ?string, project_id: ?string, shareable: bool, default_visible: bool}  $resolved
     */
    private function store(User $actor, array $resolved, bool $visible, UploadedFile $file, ?string $description): Document
    {
        $extension = $this->extension($file);
        $size = $file->getSize();

        $document = new Document(['name' => $this->displayName($file), 'description' => $description]);
        $document->forceFill([
            'owner_type' => $resolved['owner_type'],
            'owner_id' => $resolved['owner_id'],
            'client_id' => $resolved['client_id'],
            'project_id' => $resolved['project_id'],
            'uploaded_by_id' => $actor->getKey(),
            'visible_to_client' => $visible,
            'extension' => $extension,
            // Content detection, not the client's claim.
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $size === false ? 0 : $size,
        ])->save();

        $objectName = $document->getKey().($extension === null ? '' : '.'.$extension);

        $this->context->runAsSystem(static function () use ($document, $file, $objectName): void {
            $document->addMedia($file)
                ->usingName($document->name)
                ->usingFileName($objectName)
                ->toMediaCollection(Document::MEDIA_COLLECTION);
        });

        return $document;
    }

    /**
     * The lower-case last segment of the client's file name when it is a plain `[a-z0-9]{1,10}` token,
     * otherwise none (the object then has no extension part).
     */
    private function extension(UploadedFile $file): ?string
    {
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : null;
    }

    /**
     * The client's file name as a display name, kept inside the column length; a blank name falls back to
     * a neutral one so the non-blank CHECK of the table cannot fail on a pathological upload.
     */
    private function displayName(UploadedFile $file): string
    {
        $name = mb_substr(trim(basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, self::MAX_NAME_LENGTH);

        return trim($name) === '' ? 'file' : $name;
    }
}
