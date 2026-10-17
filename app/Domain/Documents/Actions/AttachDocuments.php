<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Notifications\DocumentNotifier;
use App\Domain\Documents\Support\DocumentFileName;
use App\Domain\Documents\Support\DocumentOwnerResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * The single upload entry point for files (DO-01, D-01, D-03, D-20).
 *
 * The actor needs the `attach` ability on documents for the given owner (the Admin through the policy's
 * `before()`; a Partner only on an own open task or an own non-internal comment, D-05). Everything that decides what a file
 * hangs on comes from the server: DocumentOwnerResolver derives the owner pair, the client and the project,
 * and nothing but the client's original file name (as the display name) and the description comes from the
 * request. The stored object is named `<document uuid>.<extension>` and never contains user text.
 *
 * Visibility: the caller's wish, or the owner's default when null, applies only to an owner that can be
 * shared (project, task, a non-internal comment); a client file, an unattached file and an internal comment's
 * file are stored hidden even when the caller asks for visible. The database CHECK is the backstop. A Partner
 * has no say: whatever they pass, their file is stored visible (D-05), and once the files are written, inside
 * the same transaction, every active Admin gets one bell for the submission (DocumentNotifier).
 *
 * The MIME type is detected from the file content, never taken from the client. The media write runs as
 * system after the policy passed, because `Media` stays closed to Partners. One database transaction covers
 * all files of a call.
 *
 * Every upload is untrusted (D-06, a Partner will upload too): only uploaded file objects are accepted (a
 * Filament state string is a client-controlled path), the count, the size and the extensions are checked
 * against the shared limits before anything is written, an archived owner receives no new files (D-21), and
 * the files of one call are stored all or none: when any step fails, the objects already written by this
 * call are deleted before the original exception is rethrown.
 */
final class AttachDocuments
{
    private const int MAX_DESCRIPTION_LENGTH = 2000;

    public function __construct(
        private readonly DocumentOwnerResolver $resolver,
        private readonly PartnerContext $context,
        private readonly DocumentNotifier $notifier,
    ) {}

    /**
     * @param  array<array-key, mixed>  $files  only UploadedFile instances are accepted; anything else is refused
     * @return Collection<int, Document>
     *
     * @throws ValidationException under the key 'files' for an empty list, a value that is not an uploaded
     *                             file, too many files, a file over the size limit, a disallowed extension
     *                             or an archived owner
     */
    public function handle(User $actor, ?Model $owner, array $files, ?bool $visibleToClient = null, ?string $description = null): Collection
    {
        $files = $this->uploadedFiles($files);

        Gate::forUser($actor)->authorize('attach', [Document::class, $owner]);

        $this->assertWithinLimits($files);

        $description = $this->description($description);

        /** @var list<Media> $written */
        $written = [];

        try {
            /** @var Collection<int, Document> $documents */
            $documents = DB::transaction(function () use ($actor, $owner, $files, $visibleToClient, $description, &$written): Collection {
                $this->assertOwnerNotArchived($owner);

                $resolved = $this->resolver->resolve($owner);
                $isAdmin = $actor->hasRole(RoleName::Admin->value);
                // D-05: a Partner's upload is always visible; the caller's flag is ignored for them.
                $visible = $resolved['shareable'] && ($isAdmin ? ($visibleToClient ?? $resolved['default_visible']) : true);

                $documents = new Collection;

                foreach ($files as $file) {
                    $documents->push($this->store($actor, $resolved, $visible, $file, $description, $written));
                }

                if (! $isAdmin) {
                    $this->tellAdmin($actor, $owner, $documents->count());
                }

                return $documents;
            });
        } catch (Throwable $e) {
            $this->deleteObjects($written);

            throw $e;
        }

        $this->deleteTemporaryUploads($files);

        return $documents;
    }

    /**
     * One bell per Partner submission (D-05): the task is the owner itself or the task of the owner comment,
     * read as system with archived rows included so the reference is always found.
     */
    private function tellAdmin(User $partner, ?Model $owner, int $count): void
    {
        $task = $this->context->runAsSystem(static fn (): ?Task => match (true) {
            $owner instanceof Task => Task::query()->withTrashed()->whereKey($owner->getKey())->first(),
            $owner instanceof TaskComment => Task::query()->withTrashed()
                ->whereIn('tasks.id', TaskComment::query()->whereKey($owner->getKey())->select('task_comments.task_id'))
                ->first(),
            default => null,
        });

        if ($task !== null) {
            $this->notifier->partnerUploaded($partner, $task, $count);
        }
    }

    /**
     * One byte-level path for every upload, wherever Livewire held it (D-06, D-18): the bytes are copied
     * into a local working file (at most one byte past the limit is ever read), the MIME type is detected
     * from that copy and the size taken from it, and the copy, not the original, is handed to the media
     * library. Nothing the browser declared, and no object metadata of a bucket, is believed.
     *
     * @param  array{owner_type: ?string, owner_id: ?string, client_id: ?string, project_id: ?string, shareable: bool, default_visible: bool}  $resolved
     * @param  list<Media>  $written  receives the media of this call, for cleanup when the call fails
     *
     * @throws ValidationException when the file cannot be read or is larger than the limit
     */
    private function store(User $actor, array $resolved, bool $visible, UploadedFile $file, ?string $description, array &$written): Document
    {
        $local = $this->copyToLocal($file);

        try {
            $size = filesize($local);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($local);
            $extension = DocumentFileName::extension($file->getClientOriginalName());

            $document = new Document(['name' => DocumentFileName::displayName($file->getClientOriginalName()), 'description' => $description]);
            $document->forceFill([
                'owner_type' => $resolved['owner_type'],
                'owner_id' => $resolved['owner_id'],
                'client_id' => $resolved['client_id'],
                'project_id' => $resolved['project_id'],
                'uploaded_by_id' => $actor->getKey(),
                'visible_to_client' => $visible,
                'extension' => $extension,
                // Content detection on the local copy, not the client's claim and not object metadata.
                'mime_type' => $mime === false || $mime === '' ? 'application/octet-stream' : $mime,
                'size_bytes' => $size === false ? 0 : $size,
            ])->save();

            $objectName = $document->getKey().($extension === null ? '' : '.'.$extension);

            try {
                /** @var Media $media */
                $media = $this->context->runAsSystem(static fn (): Media => $document->addMedia($local)
                    ->usingName($document->name)
                    ->usingFileName($objectName)
                    ->toMediaCollection(Document::MEDIA_COLLECTION));
            } catch (Throwable $e) {
                // A listener of the media library can fail after the object was written: the media row of this
                // document is still readable inside the transaction, so its object is recorded for the cleanup.
                foreach ($this->context->runAsSystem(static fn () => $document->media()->get()) as $partial) {
                    $written[] = $partial;
                }

                throw $e;
            }

            $written[] = $media;
        } finally {
            if (is_file($local)) {
                @unlink($local);
            }
        }

        return $document;
    }

    /**
     * Copies the bytes of an upload into a fresh local file and returns its path. The copy is bounded at
     * one byte past the limit, so an object that is larger than it claims is never read in full; a read
     * that fails or yields nothing is refused. On any refusal the local file is removed.
     *
     * @throws ValidationException
     */
    private function copyToLocal(UploadedFile $file): string
    {
        $local = tempnam(sys_get_temp_dir(), 'kokpit-upload-');

        if ($local === false) {
            throw ValidationException::withMessages(['files' => __('documents.errors.upload_unreadable')]);
        }

        $maxBytes = (int) config('kokpit.documents.max_size_kb') * 1024;
        $source = null;
        $target = null;

        try {
            $source = $file instanceof TemporaryUploadedFile
                ? $file->readStream()
                : ($file->getRealPath() === false ? false : @fopen($file->getRealPath(), 'rb'));
            $target = @fopen($local, 'wb');

            if (! is_resource($source) || ! is_resource($target)) {
                throw ValidationException::withMessages(['files' => __('documents.errors.upload_unreadable')]);
            }

            $copied = stream_copy_to_stream($source, $target, $maxBytes + 1);
        } catch (ValidationException $e) {
            $this->discard($source, $target, $local);

            throw $e;
        } catch (Throwable $e) {
            $this->discard($source, $target, $local);

            throw ValidationException::withMessages(['files' => __('documents.errors.upload_unreadable')]);
        }

        $this->discard($source, $target, null);
        clearstatcache(true, $local);

        if ($copied === false || $copied === 0) {
            @unlink($local);

            throw ValidationException::withMessages(['files' => __('documents.errors.upload_unreadable')]);
        }

        if ((int) filesize($local) > $maxBytes) {
            @unlink($local);

            throw ValidationException::withMessages(['files' => __('documents.errors.too_large', ['max' => $this->megabytes($maxBytes)])]);
        }

        return $local;
    }

    /**
     * Closes what is open and optionally removes the local file.
     *
     * @param  resource|false|null  $source
     * @param  resource|false|null  $target
     */
    private function discard(mixed $source, mixed $target, ?string $local): void
    {
        foreach ([$source, $target] as $handle) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }

        if ($local !== null && is_file($local)) {
            @unlink($local);
        }
    }

    /**
     * The shared limits (D-06), the same for the Admin and a Partner, checked for the whole list before any
     * write: how many files, how large each one is by the size the client sent, and whether any dot
     * segment of a name is a disallowed extension. All refusals are reported together under 'files'.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    private function assertWithinLimits(array $files): void
    {
        $maxFiles = (int) config('kokpit.documents.max_files_per_upload');
        $maxBytes = (int) config('kokpit.documents.max_size_kb') * 1024;

        if (count($files) > $maxFiles) {
            throw ValidationException::withMessages(['files' => __('documents.errors.too_many_files', ['max' => $maxFiles])]);
        }

        $errors = [];

        foreach ($files as $file) {
            $name = DocumentFileName::displayName($file->getClientOriginalName());

            if ($this->size($file) > $maxBytes) {
                $errors[] = __('documents.errors.too_large', ['max' => $this->megabytes($maxBytes)]);
            } elseif (DocumentFileName::hasDisallowedExtension($file->getClientOriginalName())) {
                $errors[] = __('documents.errors.extension_not_allowed', ['name' => $name]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['files' => array_values(array_unique($errors))]);
        }
    }

    private function size(UploadedFile $file): int
    {
        $size = $file->getSize();

        return $size === false ? 0 : $size;
    }

    /**
     * The limit in megabytes, at most two decimals and no trailing zeros, for the error text (ten MiB reads "10").
     */
    private function megabytes(int $bytes): string
    {
        $megabytes = rtrim(rtrim(number_format($bytes / 1048576, 2, ',', ''), '0'), ',');

        return $megabytes === '' ? '0' : $megabytes;
    }

    /**
     * An archived project, task or client, or the task of a comment, receives no new files (D-21: its
     * existing files stay). Read as system with a shared lock inside the transaction, archived rows
     * included, so the owner cannot be archived between this check and the commit.
     *
     * @throws ValidationException
     */
    private function assertOwnerNotArchived(?Model $owner): void
    {
        if ($owner === null) {
            return;
        }

        $archived = $this->context->runAsSystem(function () use ($owner): bool {
            // A comment follows its task; an owner of another class is refused later by the resolver.
            $query = match (true) {
                $owner instanceof Project => Project::query()->whereKey($owner->getKey()),
                $owner instanceof Task => Task::query()->whereKey($owner->getKey()),
                $owner instanceof Client => Client::query()->whereKey($owner->getKey()),
                $owner instanceof TaskComment => Task::query()->whereIn('tasks.id', TaskComment::query()->whereKey($owner->getKey())->select('task_comments.task_id')),
                default => null,
            };

            return $query?->withTrashed()->whereNotNull('deleted_at')->sharedLock()->exists() ?? false;
        });

        if ($archived) {
            throw ValidationException::withMessages(['files' => __('documents.errors.owner_archived')]);
        }
    }

    /**
     * Trimmed and capped; blank means none.
     */
    private function description(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : trim(mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH));
    }

    /**
     * Removes the Livewire temporary objects of files that became documents (D-18): the private bucket
     * must not keep a copy of every upload. Best effort after the commit; a failure is reported by class
     * only, because the message of a storage exception can name the object. The temporary objects of a
     * refused or failed submit are not touched, so the user can submit again; the bucket's lifecycle rule
     * expires leftovers (plan 09-24).
     *
     * @param  list<UploadedFile>  $files
     */
    private function deleteTemporaryUploads(array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                $file->delete();
            } catch (Throwable $e) {
                report(new RuntimeException('A temporary upload could not be deleted ('.$e::class.').'));
            }
        }
    }

    /**
     * Removes the objects this call already wrote, as system and best effort: a failure here never
     * masks the exception that caused the cleanup.
     *
     * @param  list<Media>  $written
     */
    private function deleteObjects(array $written): void
    {
        foreach ($written as $media) {
            try {
                $path = $media->getPathRelativeToRoot();
                $disk = Storage::disk($media->disk);

                $disk->delete($path);

                $directory = dirname($path);

                if ($directory !== '.' && $directory !== '' && $directory !== '/') {
                    $disk->deleteDirectory($directory);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Only an uploaded file object reaches storage: a Filament file state is client-controlled and can
     * be a string naming an existing path, so every other value is refused before anything is written
     * (D-06, research Pattern 3).
     *
     * @param  array<array-key, mixed>  $files
     * @return list<UploadedFile>
     *
     * @throws ValidationException
     */
    private function uploadedFiles(array $files): array
    {
        if ($files === []) {
            throw ValidationException::withMessages(['files' => __('documents.errors.empty_upload')]);
        }

        $uploads = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['files' => __('documents.errors.invalid_upload')]);
            }

            $uploads[] = $file;
        }

        return $uploads;
    }
}
