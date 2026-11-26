<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Tags\TagType;
use InvalidArgumentException;

/**
 * Shared rows of the knowledge module for every test of Phases 18 and 19.
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

    /**
     * A chain of nested folders, one inside the other, named "{prefix} 1" to "{prefix} {depth}" (the first is a
     * root folder). Returns the folders from the root to the deepest one.
     *
     * @return list<KnowledgeFolder>
     *
     * @throws InvalidArgumentException when the depth is below 1
     */
    public static function folderChain(int $depth, string $prefix = 'Example level'): array
    {
        if ($depth < 1) {
            throw new InvalidArgumentException('A folder chain needs a depth of at least 1.');
        }

        return app(PartnerContext::class)->runAsSystem(static function () use ($depth, $prefix): array {
            $chain = [];
            $parentId = null;

            for ($level = 1; $level <= $depth; $level++) {
                $folder = new KnowledgeFolder;
                $folder->forceFill(['name' => "{$prefix} {$level}", 'parent_id' => $parentId])->save();

                $chain[] = $folder;
                $parentId = $folder->getKey();
            }

            return $chain;
        });
    }

    /**
     * An article built by article() and then given knowledge tags of the given names (the knowledge tag type, so
     * the tags stay apart from the client, project and task tags).
     *
     * @param  list<string>  $tags  the tag names
     * @param  array<string, mixed>  $attributes  column overrides of article(), written with forceFill
     */
    public static function taggedArticle(array $tags, array $attributes = []): KnowledgeArticle
    {
        $article = self::article($attributes);

        app(PartnerContext::class)->runAsSystem(static function () use ($article, $tags): void {
            $article->attachTags($tags, TagType::Knowledge->value);
        });

        return $article;
    }

    /**
     * An article with a history chosen by the test: version rows 1 to n carry the given titles and bodies, and
     * the article itself holds the title and body of the last entry at version_no n (those three columns win
     * over the same keys of $attributes, so the article never disagrees with its history). An entry may name
     * `restored_from` (the number of the version it restores) for the version marker of the history tab.
     *
     * @param  list<array{title: string, body: string, restored_from?: int}>  $versions  oldest first
     * @param  array<string, mixed>  $attributes  column overrides, written with forceFill
     *
     * @throws InvalidArgumentException when the list is empty
     */
    public static function articleWithVersions(array $versions, array $attributes = [], ?User $author = null): KnowledgeArticle
    {
        if ($versions === []) {
            throw new InvalidArgumentException('An article history needs at least one version.');
        }

        $versions = array_values($versions);

        return app(PartnerContext::class)->runAsSystem(static function () use ($versions, $attributes, $author): KnowledgeArticle {
            $latest = $versions[array_key_last($versions)];

            $article = new KnowledgeArticle;
            $article->forceFill([
                'kind' => ArticleKind::General,
                'edited_at' => now(),
                'sensitive' => false,
                ...$attributes,
                'title' => $latest['title'],
                'body_md' => $latest['body'],
                'version_no' => count($versions),
            ])->save();

            $authorId = ($author ?? Canary::admin())->getKey();

            foreach ($versions as $index => $version) {
                (new KnowledgeArticleVersion)->forceFill([
                    'article_id' => $article->getKey(),
                    'version_no' => $index + 1,
                    'title' => $version['title'],
                    'body_md' => $version['body'],
                    'author_id' => $authorId,
                    'restored_from_version_no' => $version['restored_from'] ?? null,
                ])->save();
            }

            return $article;
        });
    }
}
