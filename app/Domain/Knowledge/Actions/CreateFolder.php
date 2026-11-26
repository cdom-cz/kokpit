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
 * Creates a knowledge folder, a root folder or a subfolder (ORG-01, D-03).
 *
 * The actor needs the `create` ability (the Admin only). The name goes through FolderName. Inside one transaction,
 * under the same advisory lock the database tree trigger takes, the parent must exist and be shallow enough
 * (`KnowledgeFolder::MAX_DEPTH`, never a literal) and no sibling may have the same name regardless of case, so two
 * saves racing for one name cannot both pass the check. The database stays the backstop: a unique violation
 * (23505) becomes the same name error and the tree trigger (KP003) the same depth error.
 *
 * Folder changes write no activity row: KnowledgeFolder is not logged by design (research A10, flagged for the
 * owner, who may want metadata-only folder history later).
 */
final class CreateFolder
{
    private const string DUPLICATE_NAME = '23505';

    private const string TREE_GUARD = 'KP003';

    /**
     * @throws AuthorizationException
     * @throws ValidationException a field error on `name`
     * @throws ModelNotFoundException a parent that is not a folder
     */
    public function handle(User $actor, mixed $name, ?string $parentId): KnowledgeFolder
    {
        Gate::forUser($actor)->authorize('create', KnowledgeFolder::class);

        $name = FolderName::from($name);

        if ($parentId !== null && ! Str::isUuid($parentId)) {
            throw (new ModelNotFoundException)->setModel(KnowledgeFolder::class, [$parentId]);
        }

        try {
            return DB::transaction(function () use ($name, $parentId): KnowledgeFolder {
                DB::select("select pg_advisory_xact_lock(hashtext('knowledge_folders_tree'))");

                if ($parentId !== null) {
                    $tree = FolderTree::load();

                    if (! $tree->has($parentId)) {
                        throw (new ModelNotFoundException)->setModel(KnowledgeFolder::class, [$parentId]);
                    }

                    if ($tree->depthOf($parentId) + 1 > KnowledgeFolder::MAX_DEPTH) {
                        throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.create_depth', ['max' => KnowledgeFolder::MAX_DEPTH])]);
                    }
                }

                if (FolderTree::nameTaken($parentId, $name)) {
                    throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.name_taken')]);
                }

                $folder = new KnowledgeFolder;
                $folder->forceFill(['name' => $name, 'parent_id' => $parentId])->save();

                return $folder;
            });
        } catch (QueryException $e) {
            $state = SqlState::of($e);

            if ($state === self::DUPLICATE_NAME) {
                throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.name_taken')]);
            }

            if ($state === self::TREE_GUARD) {
                throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.create_depth', ['max' => KnowledgeFolder::MAX_DEPTH])]);
            }

            throw $e;
        }
    }
}
