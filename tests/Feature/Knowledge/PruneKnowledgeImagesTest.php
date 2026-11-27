<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\PruneImages;
use App\Domain\Knowledge\Actions\RedactVersion;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Jobs\PruneKnowledgeImages;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\ImageReferences;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The daily cleanup of knowledge images (Phase 20, plan 20-09; IMG-03, D-02, D-16, D-17): a pending image no save bound
 * within 24 hours is deleted, object first and row second; an image no stored version references is stamped and deleted
 * 7 days later; an image any stored version references always stays. The disk is faked and every text is fictional.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function pruneKnowledgeImagesSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Whether the image row still exists.
 */
function pruneKnowledgeImagesRowExists(string $id): bool
{
    return pruneKnowledgeImagesSystem(static fn (): bool => KnowledgeArticleImage::query()->whereKey($id)->exists());
}

/**
 * The stored image row.
 */
function pruneKnowledgeImagesRow(string $id): KnowledgeArticleImage
{
    return pruneKnowledgeImagesSystem(static fn (): KnowledgeArticleImage => KnowledgeArticleImage::query()->findOrFail($id));
}

/**
 * One stored version row of an article.
 */
function pruneKnowledgeImagesVersion(string $articleId, int $number): KnowledgeArticleVersion
{
    return pruneKnowledgeImagesSystem(static fn (): KnowledgeArticleVersion => KnowledgeArticleVersion::query()
        ->where('article_id', $articleId)
        ->where('version_no', $number)
        ->firstOrFail());
}

/**
 * Runs the cleanup Action as the job does, in the system context, and returns its counts.
 *
 * @return array{pending: int, stamped: int, cleared: int, deleted: int}
 */
function pruneKnowledgeImagesRun(): array
{
    return pruneKnowledgeImagesSystem(static fn (): array => app(PruneImages::class)->run());
}

/**
 * An article of version 1 made by the real save, whose body names a fresh image with an object on the faked disk.
 *
 * @return array{0: KnowledgeArticle, 1: KnowledgeArticleImage}
 */
function pruneKnowledgeImagesSavedArticle(): array
{
    $admin = test()->admin;
    $image = KnowledgeFixtures::image(null, $admin);
    $body = 'Example text '.ImageReferences::markdown($image->id, 'Example alt');

    $article = app(SaveArticle::class)->create($admin, ArticleInput::from('Example article', $body, false))->article;

    return [$article, $image];
}

/**
 * Saves the next version of the article with the given body.
 */
function pruneKnowledgeImagesSave(KnowledgeArticle $article, string $body): void
{
    $article->refresh();

    app(SaveArticle::class)->update(test()->admin, $article, ArticleInput::from('Example article', $body, false), $article->version_no, $article->redaction_no);
}

/**
 * Redacts version 1 as a whole, so no stored version references the image any more once version 2 dropped it.
 */
function pruneKnowledgeImagesRedactFirst(KnowledgeArticle $article): void
{
    app(RedactVersion::class)->handle(test()->admin, $article, 1, RedactionScope::Whole, '', 'Example reason: a screenshot showed a value');
}

beforeEach(function (): void {
    Storage::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('deletes the object and the row of a pending image older than 24 hours and keeps a younger one and a referenced one', function (): void {
    $old = KnowledgeFixtures::image(null, $this->admin);
    $oldPath = $old->storagePath();

    $this->travel(25)->hours();

    $young = KnowledgeFixtures::image(null, $this->admin);

    $article = KnowledgeFixtures::article();
    $bound = KnowledgeFixtures::image($article, $this->admin);
    KnowledgeFixtures::reference(pruneKnowledgeImagesVersion($article->id, 1), $bound);

    expect(Storage::disk()->exists($oldPath))->toBeTrue();

    PruneKnowledgeImages::dispatchSync();

    expect(Storage::disk()->exists($oldPath))->toBeFalse()
        ->and(pruneKnowledgeImagesRowExists($old->id))->toBeFalse()
        ->and(pruneKnowledgeImagesRowExists($young->id))->toBeTrue()
        ->and(Storage::disk()->exists($young->storagePath()))->toBeTrue()
        ->and(pruneKnowledgeImagesRowExists($bound->id))->toBeTrue()
        ->and(Storage::disk()->exists($bound->storagePath()))->toBeTrue();
});

it('keeps a pending image up to the end of its 24 hours', function (): void {
    $pending = KnowledgeFixtures::image(null, $this->admin);

    $this->travel(23)->hours();
    PruneKnowledgeImages::dispatchSync();

    expect(pruneKnowledgeImagesRowExists($pending->id))->toBeTrue();

    $this->travel(2)->hours();
    PruneKnowledgeImages::dispatchSync();

    expect(pruneKnowledgeImagesRowExists($pending->id))->toBeFalse()
        ->and(Storage::disk()->exists($pending->storagePath()))->toBeFalse();
});

it('runs as system, so it prunes with nobody signed in', function (): void {
    $pending = KnowledgeFixtures::image(null, $this->admin);

    $this->travel(30)->hours();
    auth()->logout();

    PruneKnowledgeImages::dispatchSync();

    expect(pruneKnowledgeImagesRowExists($pending->id))->toBeFalse();
});

it('schedules the knowledge image cleanup daily on one server', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => $event->description === 'kokpit-knowledge-image-prune',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->onOneServer)->toBeTrue()
        ->and($events[0]->expression)->toBe('0 0 * * *');
});

it('never stamps or deletes an image removed from the body but still referenced by an earlier version', function (): void {
    [$article, $image] = pruneKnowledgeImagesSavedArticle();
    pruneKnowledgeImagesSave($article, 'Example text without the image');

    foreach ([0, 8, 22] as $days) {
        $this->travel($days)->days();
        $counts = pruneKnowledgeImagesRun();

        expect($counts)->toBe(['pending' => 0, 'stamped' => 0, 'cleared' => 0, 'deleted' => 0])
            ->and(pruneKnowledgeImagesRow($image->id)->orphaned_at)->toBeNull()
            ->and(Storage::disk()->exists($image->storagePath()))->toBeTrue();
    }
});

it('stamps an image no version references and deletes it, object and row, more than 7 days later', function (): void {
    [$article, $image] = pruneKnowledgeImagesSavedArticle();
    pruneKnowledgeImagesSave($article, 'Example text without the image');
    pruneKnowledgeImagesRedactFirst($article);

    expect(pruneKnowledgeImagesRun())->toBe(['pending' => 0, 'stamped' => 1, 'cleared' => 0, 'deleted' => 0])
        ->and(pruneKnowledgeImagesRow($image->id)->orphaned_at)->not->toBeNull();

    $this->travel(6)->days();

    expect(pruneKnowledgeImagesRun())->toBe(['pending' => 0, 'stamped' => 0, 'cleared' => 0, 'deleted' => 0])
        ->and(pruneKnowledgeImagesRowExists($image->id))->toBeTrue()
        ->and(Storage::disk()->exists($image->storagePath()))->toBeTrue();

    // 7 days and one hour after the stamp.
    $this->travel(25)->hours();

    expect(pruneKnowledgeImagesRun())->toBe(['pending' => 0, 'stamped' => 0, 'cleared' => 0, 'deleted' => 1])
        ->and(pruneKnowledgeImagesRowExists($image->id))->toBeFalse()
        ->and(Storage::disk()->exists($image->storagePath()))->toBeFalse();
});

it('deletes the stamped orphan through the scheduled job and keeps the article', function (): void {
    [$article, $image] = pruneKnowledgeImagesSavedArticle();
    pruneKnowledgeImagesSave($article, 'Example text without the image');
    pruneKnowledgeImagesRedactFirst($article);

    PruneKnowledgeImages::dispatchSync();
    $this->travel(8)->days();
    PruneKnowledgeImages::dispatchSync();

    expect(pruneKnowledgeImagesRowExists($image->id))->toBeFalse()
        ->and(pruneKnowledgeImagesSystem(static fn (): bool => KnowledgeArticle::query()->whereKey($article->id)->exists()))->toBeTrue();
});

it('loses the orphan stamp when a save references the image again within the grace period', function (): void {
    [$article, $image] = pruneKnowledgeImagesSavedArticle();
    pruneKnowledgeImagesSave($article, 'Example text without the image');
    pruneKnowledgeImagesRedactFirst($article);
    pruneKnowledgeImagesRun();

    expect(pruneKnowledgeImagesRow($image->id)->orphaned_at)->not->toBeNull();

    $this->travel(3)->days();
    pruneKnowledgeImagesSave($article, 'Example third '.ImageReferences::markdown($image->id, 'Example alt'));

    expect(pruneKnowledgeImagesRow($image->id)->orphaned_at)->toBeNull();

    $this->travel(8)->days();

    expect(pruneKnowledgeImagesRun())->toBe(['pending' => 0, 'stamped' => 0, 'cleared' => 0, 'deleted' => 0])
        ->and(pruneKnowledgeImagesRowExists($image->id))->toBeTrue()
        ->and(Storage::disk()->exists($image->storagePath()))->toBeTrue();
});

it('clears the stamp of an image that a reference row names again', function (): void {
    [$article, $image] = pruneKnowledgeImagesSavedArticle();
    pruneKnowledgeImagesSave($article, 'Example text without the image');
    pruneKnowledgeImagesRedactFirst($article);
    pruneKnowledgeImagesRun();

    KnowledgeFixtures::reference(pruneKnowledgeImagesVersion($article->id, 2), $image);

    expect(pruneKnowledgeImagesRun())->toBe(['pending' => 0, 'stamped' => 0, 'cleared' => 1, 'deleted' => 0])
        ->and(pruneKnowledgeImagesRow($image->id)->orphaned_at)->toBeNull();

    $this->travel(30)->days();

    expect(pruneKnowledgeImagesRun()['deleted'])->toBe(0)
        ->and(pruneKnowledgeImagesRowExists($image->id))->toBeTrue();
});

it('deletes an orphan whose object is already missing without an error', function (): void {
    [$article, $image] = pruneKnowledgeImagesSavedArticle();
    pruneKnowledgeImagesSave($article, 'Example text without the image');
    pruneKnowledgeImagesRedactFirst($article);
    pruneKnowledgeImagesRun();

    Storage::disk()->delete($image->storagePath());
    $this->travel(8)->days();

    expect(pruneKnowledgeImagesRun()['deleted'])->toBe(1)
        ->and(pruneKnowledgeImagesRowExists($image->id))->toBeFalse();
});

it('deletes a pending image whose object is already missing without an error', function (): void {
    $pending = KnowledgeFixtures::image(null, $this->admin, withObject: false);

    $this->travel(30)->hours();

    expect(pruneKnowledgeImagesRun()['pending'])->toBe(1)
        ->and(pruneKnowledgeImagesRowExists($pending->id))->toBeFalse();
});

it('finds nothing to delete on a second run right after the first', function (): void {
    [$article, $image] = pruneKnowledgeImagesSavedArticle();
    $pending = KnowledgeFixtures::image(null, $this->admin);
    pruneKnowledgeImagesSave($article, 'Example text without the image');
    pruneKnowledgeImagesRedactFirst($article);
    pruneKnowledgeImagesRun();

    $this->travel(8)->days();

    expect(pruneKnowledgeImagesRun())->toBe(['pending' => 1, 'stamped' => 0, 'cleared' => 0, 'deleted' => 1])
        ->and(pruneKnowledgeImagesRun())->toBe(['pending' => 0, 'stamped' => 0, 'cleared' => 0, 'deleted' => 0])
        ->and(pruneKnowledgeImagesRowExists($pending->id))->toBeFalse()
        ->and(pruneKnowledgeImagesRowExists($image->id))->toBeFalse();
});

it('decides from SQL over the reference table and never parses Markdown', function (): void {
    $source = (string) file_get_contents(app_path('Domain/Knowledge/Actions/PruneImages.php'));

    expect($source)->not->toContain('ImageReferences')
        ->and($source)->not->toContain('KnowledgeMarkdown')
        ->and($source)->toContain("whereDoesntHave('references')")
        ->and($source)->toContain('lockForUpdate');
});
