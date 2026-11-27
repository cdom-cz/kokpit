<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use App\Domain\Knowledge\Support\ImageReferences;
use App\Domain\Shared\Auth\PartnerContext;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The image step of SaveArticle (Phase 20, plan 20-06; IMG-03, D-02, D-04, D-16, D-17, D-18): a save binds the Admin's
 * pending images to the article and indexes every image the saved body references for the version it writes. Every
 * text is fictional; the images are rows made by KnowledgeFixtures (no object is stored here).
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function saveArticleImagesSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The reference rows of an article as `version_no:image_id` pairs, sorted.
 *
 * @return list<string>
 */
function saveArticleImagesIndex(string $articleId): array
{
    return saveArticleImagesSystem(static function () use ($articleId): array {
        $versions = KnowledgeArticleVersion::query()->where('article_id', $articleId)->pluck('version_no', 'id');

        $pairs = KnowledgeArticleVersionImage::query()
            ->where('article_id', $articleId)
            ->get()
            ->map(static fn (KnowledgeArticleVersionImage $row): string => $versions[$row->version_id].':'.$row->image_id)
            ->all();

        sort($pairs);

        return $pairs;
    });
}

/**
 * The stored image row.
 */
function saveArticleImagesRow(string $id): KnowledgeArticleImage
{
    return saveArticleImagesSystem(static fn (): KnowledgeArticleImage => KnowledgeArticleImage::query()->findOrFail($id));
}

/**
 * The number of reference rows and version rows, to prove that a refused save wrote nothing.
 *
 * @return array{references: int, versions: int}
 */
function saveArticleImagesCounts(): array
{
    return saveArticleImagesSystem(static fn (): array => [
        'references' => KnowledgeArticleVersionImage::query()->count(),
        'versions' => KnowledgeArticleVersion::query()->count(),
    ]);
}

/**
 * The Markdown of one image reference with a fictional alt text.
 */
function saveArticleImagesRef(string $id): string
{
    return ImageReferences::markdown($id, 'Example alt');
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->save = app(SaveArticle::class);
});

it('binds the pending image of the admin to a new article and indexes it for version 1', function (): void {
    $pending = KnowledgeFixtures::image(null, $this->admin, withObject: false);

    $article = $this->save->create($this->admin, ArticleInput::from('Example article', 'Example text '.saveArticleImagesRef($pending->id), false))->article;

    expect(saveArticleImagesRow($pending->id)->article_id)->toBe($article->id)
        ->and(saveArticleImagesIndex($article->id))->toBe(['1:'.$pending->id]);

    $reference = saveArticleImagesSystem(static fn (): KnowledgeArticleVersionImage => KnowledgeArticleVersionImage::query()->firstOrFail());

    expect($reference->image_id)->toBe($pending->id)
        ->and($reference->article_id)->toBe($article->id);
});

it('writes one reference row per image, however often the body names it', function (): void {
    $first = KnowledgeFixtures::image(null, $this->admin, withObject: false);
    $second = KnowledgeFixtures::image(null, $this->admin, withObject: false);
    $body = implode("\n", [
        saveArticleImagesRef($first->id),
        saveArticleImagesRef($second->id),
        saveArticleImagesRef($first->id),
    ]);

    $article = $this->save->create($this->admin, ArticleInput::from('Example article', $body, false))->article;

    $expected = ['1:'.$first->id, '1:'.$second->id];
    sort($expected);

    expect(saveArticleImagesIndex($article->id))->toBe($expected)
        ->and(saveArticleImagesRow($first->id)->article_id)->toBe($article->id)
        ->and(saveArticleImagesRow($second->id)->article_id)->toBe($article->id);
});
