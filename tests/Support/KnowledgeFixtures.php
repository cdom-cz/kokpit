<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Shared\Auth\PartnerContext;

/**
 * Shared rows of the knowledge module for every test of Phase 18.
 *
 * The three models carry no fillable list (every content write is a forceFill inside the Knowledge Actions), so a
 * test that needs a row writes it here, inside a system run, instead of repeating the inserts. The text is
 * fictional. A secret-shaped sample never comes from this class: those are assembled at runtime by SecretFixtures.
 */
final class KnowledgeFixtures
{
    /**
     * An article with one version row for every number from 1 to its version_no, each carrying the title and body
     * of the article (the history of a fixture article is a copy of its current state, which is all a visibility
     * or listing test needs). The defaults are a general, non-sensitive article of the first version, edited now.
     *
     * @param  array<string, mixed>  $attributes  column overrides, written with forceFill
     */
    public static function article(array $attributes = [], ?User $author = null): KnowledgeArticle
    {
        return app(PartnerContext::class)->runAsSystem(static function () use ($attributes, $author): KnowledgeArticle {
            $article = new KnowledgeArticle;
            $article->forceFill([
                'kind' => ArticleKind::General,
                'title' => 'Example article',
                'body_md' => '',
                'version_no' => 1,
                'edited_at' => now(),
                'sensitive' => false,
                ...$attributes,
            ])->save();

            $authorId = ($author ?? Canary::admin())->getKey();

            for ($number = 1; $number <= $article->version_no; $number++) {
                (new KnowledgeArticleVersion)->forceFill([
                    'article_id' => $article->getKey(),
                    'version_no' => $number,
                    'title' => $article->title,
                    'body_md' => $article->body_md,
                    'author_id' => $authorId,
                ])->save();
            }

            return $article;
        });
    }

    /**
     * The same article, then archived (a soft delete).
     *
     * @param  array<string, mixed>  $attributes  column overrides, written with forceFill
     */
    public static function archived(array $attributes = [], ?User $author = null): KnowledgeArticle
    {
        $article = self::article($attributes, $author);

        app(PartnerContext::class)->runAsSystem(static function () use ($article): void {
            $article->delete();
        });

        return $article;
    }

    public static function folder(string $name = 'Example folder', ?KnowledgeFolder $parent = null): KnowledgeFolder
    {
        return app(PartnerContext::class)->runAsSystem(static function () use ($name, $parent): KnowledgeFolder {
            $folder = new KnowledgeFolder;
            $folder->forceFill(['name' => $name, 'parent_id' => $parent?->getKey()])->save();

            return $folder;
        });
    }
}
