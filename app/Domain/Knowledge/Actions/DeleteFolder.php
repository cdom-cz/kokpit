<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Finance\Support\SqlState;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Exceptions\FolderNotEmpty;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Deletes an empty knowledge folder (ORG-02). A folder that holds anything is never deleted.
 *
 * The actor needs the `delete` ability (the Admin only), checked before any lookup. Inside one transaction the folder
 * row is locked and the contents are counted before anything is deleted: the articles of the folder, archived ones and
 * every kind included, because the RESTRICT foreign key counts them all, and its direct subfolders. A non-zero count
 * throws FolderNotEmpty with both numbers. The foreign keys stay the backstop: a violation (SQLSTATE 23001 of ON DELETE RESTRICT, or 23503) is mapped
 * by `refusalFor()` to the same refusal, with the numbers recounted in a fresh read.
 *
 * Folder changes write no activity row: KnowledgeFolder is not logged by design (research A10).
 */
final class DeleteFolder
{
    /**
     * The SQLSTATEs of a foreign key that refuses the delete: PostgreSQL raises 23001 (restrict_violation) for the
     * ON DELETE RESTRICT keys of the folders and 23503 (foreign_key_violation) for a deferred or NO ACTION check.
     *
     * @var list<string>
     */
    private const array FOREIGN_KEY_VIOLATIONS = ['23001', '23503'];

    /**
     * @throws AuthorizationException
     * @throws FolderNotEmpty a folder that holds a subfolder or an article, archived ones included
     * @throws ModelNotFoundException a folder that does not exist (any more)
     */
    public function handle(User $actor, string $folderId): void
    {
        Gate::forUser($actor)->authorize('delete', new KnowledgeFolder);

        if (! Str::isUuid($folderId)) {
            throw (new ModelNotFoundException)->setModel(KnowledgeFolder::class, [$folderId]);
        }

        try {
            DB::transaction(function () use ($actor, $folderId): void {
                $folder = KnowledgeFolder::query()->whereKey($folderId)->lockForUpdate()->firstOrFail();

                Gate::forUser($actor)->authorize('delete', $folder);

                [$articleCount, $subfolderCount] = self::contents($folderId);

                if ($articleCount > 0 || $subfolderCount > 0) {
                    throw new FolderNotEmpty($articleCount, $subfolderCount);
                }

                $folder->delete();
            });
        } catch (QueryException $e) {
            $refusal = self::refusalFor($e, $folderId);

            if ($refusal !== null) {
                throw $refusal;
            }

            throw $e;
        }
    }

    /**
     * Maps a foreign key violation (SQLSTATE 23001 or 23503) of the delete to FolderNotEmpty with the contents counted again.
     * Any other error, and a violation after which the folder turns out to be empty, is null and stays the caller's.
     */
    public static function refusalFor(QueryException $e, string $folderId): ?FolderNotEmpty
    {
        if (! in_array(SqlState::of($e), self::FOREIGN_KEY_VIOLATIONS, true)) {
            return null;
        }

        [$articleCount, $subfolderCount] = self::contents($folderId);

        if ($articleCount === 0 && $subfolderCount === 0) {
            return null;
        }

        return new FolderNotEmpty($articleCount, $subfolderCount);
    }

    /**
     * What blocks the delete: every article of the folder (archived and of any kind) and its direct subfolders.
     *
     * @return array{0: int, 1: int}
     */
    private static function contents(string $folderId): array
    {
        $articles = KnowledgeArticle::query()->withTrashed()->where('folder_id', $folderId)->count();
        $subfolders = KnowledgeFolder::query()->where('parent_id', $folderId)->count();

        return [$articles, $subfolders];
    }
}
