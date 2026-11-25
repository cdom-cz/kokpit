<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArchiveArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * Archive of a knowledge article (KB-07, D-13): from the view header after a confirmation, without a new version,
 * without moving the edit time, and with one metadata-only history row. Every title and body is fictional.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function archiveArticleSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A moment as it is stored: UTC.
 */
function archiveArticleMoment(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/**
 * The stored article row, archived ones included.
 */
function archiveArticleRow(string $id): KnowledgeArticle
{
    return archiveArticleSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The number of version rows of an article.
 */
function archiveArticleVersionCount(string $id): int
{
    return archiveArticleSystem(static fn (): int => KnowledgeArticleVersion::query()->where('article_id', $id)->count());
}

/**
 * Every activity row of the article, oldest first.
 *
 * @return list<Activity>
 */
function archiveArticleActivity(string $id): array
{
    return archiveArticleSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * The activity rows of the article that were not there in the snapshot taken before.
 *
 * @param  list<string>  $knownIds
 * @return list<Activity>
 */
function archiveArticleNewActivity(string $id, array $knownIds): array
{
    return array_values(array_filter(
        archiveArticleActivity($id),
        static fn (Activity $row): bool => ! in_array($row->getKey(), $knownIds, true),
    ));
}

/**
 * The keys of the activity rows of the article now.
 *
 * @return list<string>
 */
function archiveArticleActivityIds(string $id): array
{
    return array_map(static fn (Activity $row): string => (string) $row->getKey(), archiveArticleActivity($id));
}

/**
 * The stored activity rows as one JSON text, to search for content that must never be logged.
 *
 * @param  list<Activity>  $rows
 */
function archiveArticleJson(array $rows): string
{
    return (string) json_encode(array_map(static fn (Activity $row): array => $row->getAttributes(), $rows));
}

/**
 * An active article with a distinctive title and body, at the second version and an old edit time.
 */
function archiveArticleMake(): KnowledgeArticle
{
    return KnowledgeFixtures::article([
        'title' => 'Archive probe title zq7',
        'body_md' => 'Archive probe body zq7',
        'version_no' => 2,
        'edited_at' => archiveArticleMoment('2026-11-20 10:00:00'),
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('archives an article from its view screen after a confirmation and keeps its version, edit time and versions', function (): void {
    $article = archiveArticleMake();
    $known = archiveArticleActivityIds($article->id);
    $before = archiveArticleRow($article->id);

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->assertActionVisible('archive')
        ->assertActionHasLabel('archive', 'Archivovat článek')
        ->mountAction('archive')
        ->assertSee('Archivovat článek?')
        ->assertSee('Archivovaný článek zmizí ze seznamu, ale zůstane uložený i s historií. Půjde ho obnovit.')
        ->callMountedAction()
        ->assertNotified('Článek byl archivován');

    $after = archiveArticleRow($article->id);
    $new = archiveArticleNewActivity($article->id, $known);
    $archivedRows = array_values(array_filter($new, static fn (Activity $row): bool => $row->event === 'article_archived'));

    expect($after->deleted_at)->not->toBeNull()
        ->and($after->version_no)->toBe(2)
        ->and($after->edited_at->format('Y-m-d H:i:s.u'))->toBe($before->edited_at->format('Y-m-d H:i:s.u'))
        ->and($after->title)->toBe('Archive probe title zq7')
        ->and(archiveArticleVersionCount($article->id))->toBe(2)
        ->and($archivedRows)->toHaveCount(1)
        ->and($archivedRows[0]->causer_id)->toBe($this->admin->getKey())
        ->and(collect($archivedRows[0]->properties)->isEmpty())->toBeTrue();

    // A2: the package may add a row of its own for the soft delete; whatever is there carries no title and no body.
    expect(archiveArticleJson($new))->not->toContain('Archive probe title zq7')
        ->and(archiveArticleJson($new))->not->toContain('Archive probe body zq7');
});

it('moves an archived article from the default list to the archived switch', function (): void {
    $article = archiveArticleMake();
    $other = KnowledgeFixtures::article(['title' => 'Example staying article']);

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->callAction('archive');

    Livewire::test(ListKnowledgeArticles::class)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$article])
        ->filterTable('show_archived')
        ->assertCanSeeTableRecords([$other, $article]);
});

it('refuses to archive an article that is already archived and writes no second row', function (): void {
    $article = KnowledgeFixtures::archived(['title' => 'Archive probe title zq7', 'body_md' => 'Archive probe body zq7']);
    $known = archiveArticleActivityIds($article->id);
    $archived = archiveArticleRow($article->id);

    expect(fn () => app(ArchiveArticle::class)->handle($this->admin, $archived))
        ->toThrow(ModelNotFoundException::class)
        ->and(archiveArticleNewActivity($article->id, $known))->toBe([]);
});
