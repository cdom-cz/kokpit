<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArchiveArticle;
use App\Domain\Knowledge\Actions\RestoreArticle;
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

it('shows archive and no restore on the view header of an active article, and the reverse on an archived one', function (): void {
    $active = archiveArticleMake();
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $active->getRouteKey()])
        ->assertActionVisible('archive')
        ->assertActionHidden('restore');

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $archived->getRouteKey()])
        ->assertActionHidden('archive')
        ->assertActionVisible('restore')
        ->assertActionHasLabel('restore', 'Obnovit článek');
});

it('offers archive as an icon button with a tooltip on an active row and restore on an archived one', function (): void {
    $active = KnowledgeFixtures::article(['title' => 'Example active article']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);

    $component = Livewire::test(ListKnowledgeArticles::class)
        ->assertTableActionVisible('archive', $active)
        ->assertTableActionHidden('restore', $active)
        ->filterTable('show_archived')
        ->assertTableActionHidden('archive', $archived)
        ->assertTableActionVisible('restore', $archived)
        ->assertTableActionVisible('archive', $active);

    $table = $component->instance()->getTable();
    $archive = $table->getAction('archive')?->record($active);
    $restore = $table->getAction('restore')?->record($archived);

    expect($archive?->isIconButton())->toBeTrue()
        ->and($archive?->getLabel())->toBe('Archivovat článek')
        ->and($archive?->getTooltip())->toBe('Archivovat článek')
        ->and($restore?->isIconButton())->toBeTrue()
        ->and($restore?->getLabel())->toBe('Obnovit článek')
        ->and($restore?->getTooltip())->toBe('Obnovit článek')
        ->and($table->getAction('forceDelete'))->toBeNull()
        ->and($table->getFlatBulkActions())->toBe([]);
});

it('archives an article from its list row after the same confirmation', function (): void {
    $article = archiveArticleMake();
    $known = archiveArticleActivityIds($article->id);

    Livewire::test(ListKnowledgeArticles::class)
        ->mountTableAction('archive', $article)
        ->assertSee('Archivovat článek?')
        ->callMountedTableAction()
        ->assertNotified('Článek byl archivován')
        ->assertCanNotSeeTableRecords([$article]);

    $rows = array_filter(archiveArticleNewActivity($article->id, $known), static fn (Activity $row): bool => $row->event === 'article_archived');

    expect(archiveArticleRow($article->id)->deleted_at)->not->toBeNull()
        ->and(archiveArticleRow($article->id)->version_no)->toBe(2)
        ->and($rows)->toHaveCount(1);
});

it('restores an archived article from the archived switch without a confirmation and keeps its place in the list', function (): void {
    $older = KnowledgeFixtures::archived([
        'title' => 'Archive probe title zq7',
        'body_md' => 'Archive probe body zq7',
        'version_no' => 3,
        'edited_at' => archiveArticleMoment('2026-11-18 10:00:00'),
    ]);
    $newer = KnowledgeFixtures::article(['title' => 'Example newer article', 'edited_at' => archiveArticleMoment('2026-11-24 10:00:00')]);
    $oldest = KnowledgeFixtures::article(['title' => 'Example oldest article', 'edited_at' => archiveArticleMoment('2026-11-10 10:00:00')]);
    $known = archiveArticleActivityIds($older->id);
    $before = archiveArticleRow($older->id);

    Livewire::test(ListKnowledgeArticles::class)
        ->filterTable('show_archived')
        ->callTableAction('restore', $older)
        ->assertNotified('Článek byl obnoven');

    $after = archiveArticleRow($older->id);
    $new = archiveArticleNewActivity($older->id, $known);
    $restored = array_values(array_filter($new, static fn (Activity $row): bool => $row->event === 'article_restored'));

    expect($after->deleted_at)->toBeNull()
        ->and($after->version_no)->toBe(3)
        ->and($after->edited_at->format('Y-m-d H:i:s.u'))->toBe($before->edited_at->format('Y-m-d H:i:s.u'))
        ->and(archiveArticleVersionCount($older->id))->toBe(3)
        ->and($restored)->toHaveCount(1)
        ->and($restored[0]->causer_id)->toBe($this->admin->getKey())
        ->and(collect($restored[0]->properties)->isEmpty())->toBeTrue()
        ->and(archiveArticleJson($new))->not->toContain('Archive probe title zq7')
        ->and(archiveArticleJson($new))->not->toContain('Archive probe body zq7');

    // D-13: the list is sorted by the last edit, so the restored article returns between the other two.
    Livewire::test(ListKnowledgeArticles::class)
        ->assertCanSeeTableRecords([$newer, $older, $oldest], inOrder: true);
});

it('restores an archived article from its view screen', function (): void {
    $article = KnowledgeFixtures::archived(['title' => 'Archive probe title zq7', 'body_md' => 'Archive probe body zq7']);

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->callAction('restore')
        ->assertNotified('Článek byl obnoven')
        ->assertActionVisible('archive')
        ->assertActionHidden('restore');

    expect(archiveArticleRow($article->id)->deleted_at)->toBeNull();
});

it('refuses to restore an article that is active and writes no row', function (): void {
    $article = archiveArticleMake();
    $known = archiveArticleActivityIds($article->id);

    expect(fn () => app(RestoreArticle::class)->handle($this->admin, $article))
        ->toThrow(ModelNotFoundException::class)
        ->and(archiveArticleNewActivity($article->id, $known))->toBe([]);
});

it('refuses to archive an article that is already archived and writes no second row', function (): void {
    $article = KnowledgeFixtures::archived(['title' => 'Archive probe title zq7', 'body_md' => 'Archive probe body zq7']);
    $known = archiveArticleActivityIds($article->id);
    $archived = archiveArticleRow($article->id);

    expect(fn () => app(ArchiveArticle::class)->handle($this->admin, $archived))
        ->toThrow(ModelNotFoundException::class)
        ->and(archiveArticleNewActivity($article->id, $known))->toBe([]);
});
