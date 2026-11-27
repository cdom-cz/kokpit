<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\ImageReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The image step of a version-writing save (IMG-03, D-02, D-04, D-16): it decides which images a body may name, binds
 * the Admin's pending ones to the article and writes the reference rows that tell the cleanup which image each stored
 * version still needs. Only SaveArticle calls it, always inside the save transaction.
 *
 * `lockReferenced()` runs after the guard decision: it reads every uuid the body names (`ImageReferences::in()`),
 * locks those image rows with `FOR UPDATE` in id order and refuses the save when one of them belongs to another
 * article or is a pending image of another user (D-04). A uuid that finds no row is skipped silently: an unknown id
 * costs nothing and a deleted image must never make a restore fail (D-18).
 *
 * `bindAndIndex()` runs after the version row exists: it binds each accepted pending image to the article, clears the
 * orphan stamp of an accepted image a save names again, and inserts one reference row per accepted image for the new
 * version (D-16). Rows of older versions are never touched here, so an image stays referenced while any stored
 * version names it (D-17).
 *
 * Lock order: the caller holds the article row (the update locks it first, a create has no row yet); the image rows
 * follow in id order, so two saves that bind the same pending image to two articles queue on the image row and the
 * second one is refused as foreign.
 *
 * `ImageReferences::in()` is a superset of what the renderer shows: a uuid inside a code block or a link also binds
 * and is indexed. That only keeps an image a little longer, which is the safe direction.
 *
 * Nothing is logged: an image row holds a file name, and this class writes no activity and calls no logger.
 */
final class BindArticleImages
{
    /**
     * Locks the image rows the body references and refuses any that this save may not use.
     *
     * @param  KnowledgeArticle|null  $article  the locked article of an update, or null for a create
     * @return list<KnowledgeArticleImage> the accepted rows, in id order
     *
     * @throws ValidationException a reference to an image of another article or a pending image of another user, on `body`
     */
    public function lockReferenced(string $body, ?KnowledgeArticle $article, User $actor): array
    {
        $ids = ImageReferences::in($body);

        if ($ids === []) {
            return [];
        }

        $rows = KnowledgeArticleImage::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();

        foreach ($rows as $row) {
            $ownedByArticle = $article !== null && $row->article_id !== null && $row->article_id === $article->getKey();
            $pendingOfActor = $row->article_id === null && $row->uploaded_by === $actor->getKey();

            if (! $ownedByArticle && ! $pendingOfActor) {
                throw ValidationException::withMessages(['body' => __('knowledge.images.errors.foreign_reference')]);
            }
        }

        return array_values($rows);
    }

    /**
     * Binds the pending rows to the article, clears the orphan stamps and indexes every accepted image for the version.
     *
     * @param  list<KnowledgeArticleImage>  $images  the rows `lockReferenced()` returned for the same body
     *
     * @throws LogicException a locked pending row that is no longer pending
     */
    public function bindAndIndex(KnowledgeArticle $article, KnowledgeArticleVersion $version, array $images): void
    {
        if ($images === []) {
            return;
        }

        $rows = [];

        foreach ($images as $image) {
            if ($image->article_id === null) {
                $changed = KnowledgeArticleImage::query()
                    ->whereKey($image->getKey())
                    ->whereNull('article_id')
                    ->toBase()
                    ->update(['article_id' => $article->getKey()]);

                if ($changed !== 1) {
                    throw new LogicException('A locked pending image could not be bound.');
                }
            }

            if ($image->orphaned_at !== null) {
                KnowledgeArticleImage::query()
                    ->whereKey($image->getKey())
                    ->toBase()
                    ->update(['orphaned_at' => null]);
            }

            $rows[] = [
                'version_id' => $version->getKey(),
                'image_id' => $image->getKey(),
                'article_id' => $article->getKey(),
            ];
        }

        DB::table('knowledge_article_version_images')->insert($rows);
    }
}
