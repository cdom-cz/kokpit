<?php

declare(strict_types=1);

use App\Domain\Knowledge\Jobs\PruneKnowledgeImages;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
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
