<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArticleClassification;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\RestoreVersion;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use App\Domain\Knowledge\Support\ImageReferences;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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

/**
 * One stored version row of an article.
 */
function saveArticleImagesVersion(string $articleId, int $number): KnowledgeArticleVersion
{
    return saveArticleImagesSystem(static fn (): KnowledgeArticleVersion => KnowledgeArticleVersion::query()
        ->where('article_id', $articleId)
        ->where('version_no', $number)
        ->firstOrFail());
}

/**
 * The messages of the field error a callback throws on `body`, or null when it throws none.
 *
 * @return list<string>|null
 */
function saveArticleImagesBodyError(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors()['body'] ?? [];
    }

    return null;
}

/**
 * An article of two versions whose bodies name the given image id; the image does not exist yet, so a test creates
 * it with that id afterwards.
 */
function saveArticleImagesArticleNaming(string $imageId): KnowledgeArticle
{
    return KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example article', 'body' => 'Example first '.saveArticleImagesRef($imageId)],
        ['title' => 'Example article', 'body' => 'Example second '.saveArticleImagesRef($imageId)],
    ]);
}

it('indexes each new version with the images it keeps and leaves the older rows alone', function (): void {
    $id = (string) Str::uuid7();
    $article = saveArticleImagesArticleNaming($id);
    $image = KnowledgeFixtures::image($article, $this->admin, ['id' => $id], withObject: false);
    KnowledgeFixtures::reference(saveArticleImagesVersion($article->id, 1), $image);

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example third '.saveArticleImagesRef($id), false), 2, 0);

    expect(saveArticleImagesIndex($article->id))->toBe(['1:'.$id, '3:'.$id]);
});

it('writes no row for a version that drops the reference and keeps every older row', function (): void {
    $id = (string) Str::uuid7();
    $article = saveArticleImagesArticleNaming($id);
    $image = KnowledgeFixtures::image($article, $this->admin, ['id' => $id], withObject: false);
    KnowledgeFixtures::reference(saveArticleImagesVersion($article->id, 1), $image);
    KnowledgeFixtures::reference(saveArticleImagesVersion($article->id, 2), $image);

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example third without the image', false), 2, 0);

    expect(saveArticleImagesIndex($article->id))->toBe(['1:'.$id, '2:'.$id])
        ->and(saveArticleImagesRow($id)->article_id)->toBe($article->id);
});

it('binds a pending image to an existing article on update', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example text']);
    $pending = KnowledgeFixtures::image(null, $this->admin, withObject: false);

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example text '.saveArticleImagesRef($pending->id), false), 1, 0);

    expect(saveArticleImagesRow($pending->id)->article_id)->toBe($article->id)
        ->and(saveArticleImagesIndex($article->id))->toBe(['2:'.$pending->id]);
});

it('writes no reference row for an unchanged, a sensitivity-only or a classification-only save', function (): void {
    $id = (string) Str::uuid7();
    $body = 'Example text '.saveArticleImagesRef($id);
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => $body]);
    KnowledgeFixtures::image($article, $this->admin, ['id' => $id], withObject: false);
    $folder = KnowledgeFixtures::folder();

    $unchanged = $this->save->update($this->admin, $article, ArticleInput::from('Example article', $body, false), 1, 0);
    $sensitivity = $this->save->update($this->admin, $article, ArticleInput::from('Example article', $body, true), 1, 0);
    $classification = $this->save->update($this->admin, $article, ArticleInput::from('Example article', $body, false, classification: ArticleClassification::from($folder->id, null, null, [])), 1, 0);

    expect($unchanged->outcome)->toBe(SaveOutcome::Unchanged)
        ->and($sensitivity->outcome)->toBe(SaveOutcome::SensitivityChanged)
        ->and($classification->outcome)->toBe(SaveOutcome::ClassificationChanged)
        ->and(saveArticleImagesIndex($article->id))->toBe([]);
});

it('refuses a body that names an image of another article, on create and on update, and writes nothing', function (): void {
    $other = KnowledgeFixtures::article(['title' => 'Example other article']);
    $foreign = KnowledgeFixtures::image($other, $this->admin, withObject: false);
    $mine = KnowledgeFixtures::image(null, $this->admin, withObject: false);
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example text']);
    $body = saveArticleImagesRef($mine->id).' '.saveArticleImagesRef($foreign->id);
    $counts = saveArticleImagesCounts();
    $message = 'Text odkazuje na obrázek jiného článku. Obrázky nejde sdílet mezi články. Nahrajte ho do tohoto článku znovu.';

    $onCreate = saveArticleImagesBodyError(fn () => $this->save->create($this->admin, ArticleInput::from('Example new article', $body, false)));
    $onUpdate = saveArticleImagesBodyError(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', $body, false), 1, 0));

    expect($onCreate)->toBe([$message])
        ->and($onUpdate)->toBe([$message])
        ->and(saveArticleImagesCounts())->toBe($counts)
        ->and(saveArticleImagesRow($foreign->id)->article_id)->toBe($other->id)
        ->and(saveArticleImagesRow($mine->id)->article_id)->toBeNull()
        ->and(saveArticleImagesSystem(static fn (): int => KnowledgeArticle::query()->where('title', 'Example new article')->count()))->toBe(0);
});

it('refuses a body that names the pending image of another admin and leaves it pending', function (): void {
    $second = Canary::admin();
    $theirs = KnowledgeFixtures::image(null, $second, withObject: false);
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example text']);
    $counts = saveArticleImagesCounts();

    $onCreate = saveArticleImagesBodyError(fn () => $this->save->create($this->admin, ArticleInput::from('Example new article', saveArticleImagesRef($theirs->id), false)));
    $onUpdate = saveArticleImagesBodyError(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', saveArticleImagesRef($theirs->id), false), 1, 0));

    expect($onCreate)->not->toBeNull()
        ->and($onUpdate)->not->toBeNull()
        ->and(saveArticleImagesCounts())->toBe($counts)
        ->and(saveArticleImagesRow($theirs->id)->article_id)->toBeNull();
});

it('shows the foreign reference refusal under the body field of the edit screen and keeps the typed text', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $other = KnowledgeFixtures::article(['title' => 'Example other article']);
    $foreign = KnowledgeFixtures::image($other, $this->admin, withObject: false);
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example text']);
    $typed = 'Example typed text '.saveArticleImagesRef($foreign->id);

    Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->fillForm(['body' => $typed])
        ->call('save')
        ->assertHasErrors(['data.body'])
        ->assertSee('Text odkazuje na obrázek jiného článku')
        ->assertSet('data.body', $typed);

    expect(saveArticleImagesSystem(static fn (): int => KnowledgeArticle::query()->findOrFail($article->id)->version_no))->toBe(1);
});

it('skips a reference to an unknown image without an error and indexes nothing for it', function (): void {
    $unknown = (string) Str::uuid7();

    $article = $this->save->create($this->admin, ArticleInput::from('Example article', 'Example text '.saveArticleImagesRef($unknown), false))->article;

    expect($article->version_no)->toBe(1)
        ->and(saveArticleImagesIndex($article->id))->toBe([]);
});

it('restores a version whose image was deleted without an error and indexes nothing for it', function (): void {
    $id = (string) Str::uuid7();
    $article = saveArticleImagesArticleNaming($id);
    $image = KnowledgeFixtures::image($article, $this->admin, ['id' => $id], withObject: false);
    KnowledgeFixtures::reference(saveArticleImagesVersion($article->id, 1), $image);

    // The way the delete-for-good of a later plan removes an image: the reference rows first, then the row.
    saveArticleImagesSystem(static function () use ($id): void {
        DB::table('knowledge_article_version_images')->where('image_id', $id)->delete();
        DB::table('knowledge_article_images')->where('id', $id)->delete();
    });

    $result = app(RestoreVersion::class)->handle($this->admin, $article, 1, 2, 0);

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and($result->article->version_no)->toBe(3)
        ->and(saveArticleImagesIndex($article->id))->toBe([]);
});

it('indexes the restored version again for an image that still exists', function (): void {
    $id = (string) Str::uuid7();
    $article = saveArticleImagesArticleNaming($id);
    $image = KnowledgeFixtures::image($article, $this->admin, ['id' => $id], withObject: false);
    KnowledgeFixtures::reference(saveArticleImagesVersion($article->id, 1), $image);

    app(RestoreVersion::class)->handle($this->admin, $article, 1, 2, 0);

    expect(saveArticleImagesIndex($article->id))->toBe(['1:'.$id, '3:'.$id]);
});

it('clears the orphan stamp of an image that a save names again', function (): void {
    $id = (string) Str::uuid7();
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example text']);
    KnowledgeFixtures::image($article, $this->admin, ['id' => $id, 'orphaned_at' => now()], withObject: false);

    expect(saveArticleImagesRow($id)->orphaned_at)->not->toBeNull();

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example text '.saveArticleImagesRef($id), false), 1, 0);

    expect(saveArticleImagesRow($id)->orphaned_at)->toBeNull()
        ->and(saveArticleImagesIndex($article->id))->toBe(['2:'.$id]);
});
