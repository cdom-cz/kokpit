<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\RedactVersion;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use App\Domain\Knowledge\Support\ImageReferences;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * A redaction keeps the image reference index true (Phase 20, plan 20-06; D-16, D-17, research Pitfall 4): the
 * reference rows of the rewritten version that its new text no longer names are deleted, the rows of every other
 * version stay. Every text is fictional; the images are rows made by KnowledgeFixtures (no object is stored).
 */

const REDACT_VERSION_IMAGES_REASON = 'Example reason: a screenshot showed a value';

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function redactVersionImagesSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The reference rows of an article as `version_no:image_id` pairs, sorted.
 *
 * @return list<string>
 */
function redactVersionImagesIndex(string $articleId): array
{
    return redactVersionImagesSystem(static function () use ($articleId): array {
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
 * The Markdown of one image reference with a fictional alt text.
 */
function redactVersionImagesRef(string $id): string
{
    return ImageReferences::markdown($id, 'Example alt');
}

/**
 * Binds the given image ids to the article as rows and indexes them for the given versions, the way a save does.
 *
 * @param  array<int, list<string>>  $referencedBy  version number => image ids it references
 */
function redactVersionImagesIndexed(KnowledgeArticle $article, array $referencedBy): void
{
    $images = [];

    foreach ($referencedBy as $number => $ids) {
        $version = redactVersionImagesSystem(static fn (): KnowledgeArticleVersion => KnowledgeArticleVersion::query()
            ->where('article_id', $article->id)
            ->where('version_no', $number)
            ->firstOrFail());

        foreach ($ids as $id) {
            $images[$id] ??= KnowledgeFixtures::image($article, test()->admin, ['id' => $id], withObject: false);
            KnowledgeFixtures::reference($version, $images[$id]);
        }
    }
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->redact = app(RedactVersion::class);
});

it('removes the reference of a redacted line and keeps the one of the lines that stay', function (): void {
    $x = (string) Str::uuid7();
    $y = (string) Str::uuid7();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example title', 'body' => 'First '.redactVersionImagesRef($x)."\nSecond line\nThird ".redactVersionImagesRef($y)],
        ['title' => 'Example title', 'body' => 'Example current body'],
    ]);
    redactVersionImagesIndexed($article, [1 => [$x, $y]]);

    $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '1', REDACT_VERSION_IMAGES_REASON);

    expect(redactVersionImagesIndex($article->id))->toBe(['1:'.$y]);
});

it('removes every reference of a version redacted as a whole', function (): void {
    $x = (string) Str::uuid7();
    $y = (string) Str::uuid7();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example title', 'body' => 'First '.redactVersionImagesRef($x)."\nThird ".redactVersionImagesRef($y)],
        ['title' => 'Example title', 'body' => 'Example current body'],
    ]);
    redactVersionImagesIndexed($article, [1 => [$x, $y]]);

    $this->redact->handle($this->admin, $article, 1, RedactionScope::Whole, '', REDACT_VERSION_IMAGES_REASON);

    expect(redactVersionImagesIndex($article->id))->toBe([]);
});

it('resyncs the rows of the current version together with the article body', function (): void {
    $x = (string) Str::uuid7();
    $y = (string) Str::uuid7();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example title', 'body' => 'First '.redactVersionImagesRef($x)."\nSecond ".redactVersionImagesRef($y)],
    ]);
    redactVersionImagesIndexed($article, [1 => [$x, $y]]);

    $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '2', REDACT_VERSION_IMAGES_REASON);

    $stored = redactVersionImagesSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->findOrFail($article->id));

    expect($stored->body_md)->not->toContain($y)
        ->and($stored->body_md)->toContain($x)
        ->and(redactVersionImagesIndex($article->id))->toBe(['1:'.$x]);
});

it('keeps the row of a later version that still references the image', function (): void {
    $x = (string) Str::uuid7();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example title', 'body' => 'First '.redactVersionImagesRef($x)],
        ['title' => 'Example title', 'body' => 'Second '.redactVersionImagesRef($x)],
    ]);
    redactVersionImagesIndexed($article, [1 => [$x], 2 => [$x]]);

    $this->redact->handle($this->admin, $article, 1, RedactionScope::Whole, '', REDACT_VERSION_IMAGES_REASON);

    expect(redactVersionImagesIndex($article->id))->toBe(['2:'.$x]);
});

it('deletes no reference row when the redaction fails', function (): void {
    $x = (string) Str::uuid7();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example title', 'body' => 'First '.redactVersionImagesRef($x)."\n\nThird line"],
        ['title' => 'Example title', 'body' => 'Example current body'],
    ]);
    redactVersionImagesIndexed($article, [1 => [$x]]);

    $refused = null;

    try {
        // Line 2 is blank, so there is nothing to redact.
        $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '2', REDACT_VERSION_IMAGES_REASON);
    } catch (ValidationException $e) {
        $refused = $e;
    }

    expect($refused)->toBeInstanceOf(ValidationException::class)
        ->and(redactVersionImagesIndex($article->id))->toBe(['1:'.$x]);
});
