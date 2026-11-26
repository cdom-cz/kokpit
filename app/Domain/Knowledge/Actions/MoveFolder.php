<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Finance\Support\SqlState;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Exceptions\FolderMoveRefused;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Knowledge\Queries\FolderTree;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Moves a knowledge folder, with its subfolders and articles, under another parent or to the root (ORG-01, D-03).
 *
 * The actor needs the `update` ability (the Admin only), checked before any lookup. Inside one transaction the Action
 * first takes the advisory lock of the tree, the same key the database tree trigger takes (the lock is re-entrant in
 * the session), so two moves racing each other are checked one after the other and can never form a cycle together.
 * Under the lock the tree is read once and the move rule of FolderTree decides: a parent that is the folder itself or
 * lies in its subtree is a `cycle`, and a result deeper than `KnowledgeFolder::MAX_DEPTH` (the folder counted with its
 * subfolders) is `depth`; both throw FolderMoveRefused with the cap in it. A destination that already holds a sibling
 * of the same name regardless of case is a field error on `parent_id`. The same parent as now is a no-op.
 *
 * The database trigger (SQLSTATE KP003) and the unique index (23505) are the backstop; `refusalFor()` maps the trigger
 * error by its message to the same two reasons.
 *
 * Folder changes write no activity row: KnowledgeFolder is not logged by design (research A10).
 */
final class MoveFolder
{
    private const string DUPLICATE_NAME = '23505';

    private const string TREE_GUARD = 'KP003';

    /**
     * @throws AuthorizationException
     * @throws FolderMoveRefused a move into the own subtree or past the depth cap
     * @throws ValidationException a field error on `parent_id` (a sibling of that name exists there)
     * @throws ModelNotFoundException a folder or parent that does not exist (any more)
     */
    public function handle(User $actor, string $folderId, ?string $parentId): KnowledgeFolder
    {
        Gate::forUser($actor)->authorize('update', new KnowledgeFolder);

        foreach ([$folderId, $parentId] as $id) {
            if ($id !== null && ! Str::isUuid($id)) {
                throw (new ModelNotFoundException)->setModel(KnowledgeFolder::class, [$id]);
            }
        }

        try {
            return DB::transaction(function () use ($actor, $folderId, $parentId): KnowledgeFolder {
                DB::select("select pg_advisory_xact_lock(hashtext('knowledge_folders_tree'))");

                $folder = KnowledgeFolder::query()->whereKey($folderId)->lockForUpdate()->firstOrFail();

                Gate::forUser($actor)->authorize('update', $folder);

                $tree = FolderTree::load();

                if ($parentId !== null && ! $tree->has($parentId)) {
                    throw (new ModelNotFoundException)->setModel(KnowledgeFolder::class, [$parentId]);
                }

                if ($folder->parent_id === $parentId) {
                    return $folder;
                }

                $reason = $tree->moveRefusal($folderId, $parentId);

                if ($reason !== null) {
                    throw new FolderMoveRefused($reason);
                }

                if (FolderTree::nameTaken($parentId, $folder->name, $folder->id)) {
                    throw ValidationException::withMessages(['parent_id' => __('knowledge.folders.errors.name_taken')]);
                }

                $folder->forceFill(['parent_id' => $parentId])->save();

                return $folder;
            });
        } catch (QueryException $e) {
            $refusal = self::refusalFor($e);

            if ($refusal !== null) {
                throw $refusal;
            }

            if (SqlState::of($e) === self::DUPLICATE_NAME) {
                throw ValidationException::withMessages(['parent_id' => __('knowledge.folders.errors.name_taken')]);
            }

            throw $e;
        }
    }

    /**
     * Maps the refusal of the database tree trigger (SQLSTATE KP003) to the reason the Admin is shown, by the message
     * of the database error: "own parent" and "own subtree" are a cycle, "nest at most" is too deep. Any other error
     * (another SQLSTATE or another KP003 message) is null and stays the caller's to handle.
     */
    public static function refusalFor(QueryException $e): ?FolderMoveRefused
    {
        if (SqlState::of($e) !== self::TREE_GUARD) {
            return null;
        }

        $previous = $e->getPrevious();
        $message = $previous instanceof Throwable ? $previous->getMessage() : $e->getMessage();

        if (str_contains($message, 'own parent') || str_contains($message, 'own subtree')) {
            return new FolderMoveRefused(FolderMoveRefused::CYCLE);
        }

        if (str_contains($message, 'nest at most')) {
            return new FolderMoveRefused(FolderMoveRefused::DEPTH);
        }

        return null;
    }
}
