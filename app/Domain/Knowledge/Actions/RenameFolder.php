<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Finance\Support\SqlState;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Knowledge\Queries\FolderTree;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Renames a knowledge folder (ORG-01).
 *
 * The actor needs the `update` ability (the Admin only). The ability is checked before the folder is looked up, so a
 * caller without it learns nothing about which ids exist. The name goes through FolderName and must differ from the
 * names of the siblings regardless of case, the folder itself left out, so a change of case alone is a rename. The
 * folder row is locked while the name is checked and written; a unique violation (23505) of two racing saves
 * becomes the same name error. An unchanged name returns the folder without a write.
 *
 * Folder changes write no activity row: KnowledgeFolder is not logged by design (research A10).
 */
final class RenameFolder
{
    private const string DUPLICATE_NAME = '23505';

    /**
     * @throws AuthorizationException
     * @throws ValidationException a field error on `name`
     * @throws ModelNotFoundException a folder that does not exist (any more)
     */
    public function handle(User $actor, string $folderId, mixed $name): KnowledgeFolder
    {
        Gate::forUser($actor)->authorize('update', new KnowledgeFolder);

        $name = FolderName::from($name);

        if (! Str::isUuid($folderId)) {
            throw (new ModelNotFoundException)->setModel(KnowledgeFolder::class, [$folderId]);
        }

        try {
            return DB::transaction(function () use ($actor, $folderId, $name): KnowledgeFolder {
                $folder = KnowledgeFolder::query()->whereKey($folderId)->lockForUpdate()->firstOrFail();

                Gate::forUser($actor)->authorize('update', $folder);

                if ($folder->name === $name) {
                    return $folder;
                }

                if (FolderTree::nameTaken($folder->parent_id, $name, $folder->id)) {
                    throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.name_taken')]);
                }

                $folder->forceFill(['name' => $name])->save();

                return $folder;
            });
        } catch (QueryException $e) {
            if (SqlState::of($e) === self::DUPLICATE_NAME) {
                throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.name_taken')]);
            }

            throw $e;
        }
    }
}
