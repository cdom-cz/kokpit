<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The edit screen of a general knowledge article (KB-02, KB-06, KB-08, VER-01, SEC-01 to SEC-03, D-04, D-06, D-08 to
 * D-10): the entry points, the save through SaveArticle::update(), the stale-save notice, the outcome notifications and
 * the secret guard on edit. Every text is fictional; secret-shaped samples come only from SecretFixtures.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function editArticleSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article row, archived ones included.
 */
function editArticleRow(string $id): KnowledgeArticle
{
    return editArticleSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version row of an article with the given number, or null.
 */
function editArticleVersion(string $id, int $number): ?KnowledgeArticleVersion
{
    return editArticleSystem(static fn (): ?KnowledgeArticleVersion => KnowledgeArticleVersion::query()
        ->where('article_id', $id)
        ->where('version_no', $number)
        ->first());
}

/**
 * The number of version rows of an article.
 */
function editArticleVersionCount(string $id): int
{
    return editArticleSystem(static fn (): int => KnowledgeArticleVersion::query()->where('article_id', $id)->count());
}

/**
 * The edit screen of an article.
 */
function editArticlePage(KnowledgeArticle $article): Testable
{
    return Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()]);
}

/**
 * The view URL of an article.
 */
function editArticleViewUrl(KnowledgeArticle $article): string
{
    return KnowledgeArticleResource::getUrl('view', ['record' => $article]);
}

/**
 * The edit URL of an article.
 */
function editArticleUrl(KnowledgeArticle $article): string
{
    return KnowledgeArticleResource::getUrl('edit', ['record' => $article]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('offers the edit action first in the header of an active article and hides it for an archived one', function (): void {
    $active = KnowledgeFixtures::article(['title' => 'Example active article', 'body_md' => 'Example active body']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article', 'body_md' => 'Example archived body']);

    $page = Livewire::test(ViewKnowledgeArticle::class, ['record' => $active->getRouteKey()])
        ->assertActionVisible('edit')
        ->assertActionHasLabel('edit', 'Upravit článek')
        ->assertActionHasUrl('edit', editArticleUrl($active));

    $names = array_map(static fn (object $action): string => $action->getName(), array_values($page->instance()->getCachedHeaderActions()));

    expect($names[0])->toBe('edit');

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $archived->getRouteKey()])->assertActionHidden('edit');
});

it('offers the edit action as an icon button with label and tooltip on an active row only', function (): void {
    $active = KnowledgeFixtures::article(['title' => 'Example active article']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);

    $component = Livewire::test(ListKnowledgeArticles::class)
        ->assertTableActionVisible('edit', $active)
        ->assertTableActionHasUrl('edit', editArticleUrl($active), $active)
        ->filterTable('show_archived')
        ->assertTableActionHidden('edit', $archived)
        ->assertTableActionVisible('edit', $active);

    $table = $component->instance()->getTable();
    $edit = $table->getAction('edit')?->record($active);

    expect($edit?->isIconButton())->toBeTrue()
        ->and($edit?->getLabel())->toBe('Upravit článek')
        ->and($edit?->getTooltip())->toBe('Upravit článek');

    // The edit icon comes before archive and restore.
    $names = array_map(static fn (object $action): string => $action->getName(), array_values($table->getFlatActions()));

    expect($names)->toBe(['edit', 'archive', 'restore']);
});

it('links the empty-body text of an active article to its edit screen and gives an archived one no link', function (): void {
    $empty = KnowledgeFixtures::article(['title' => 'Example empty article', 'body_md' => '']);
    $filled = KnowledgeFixtures::article(['title' => 'Example filled article', 'body_md' => 'Example text']);
    $archivedEmpty = KnowledgeFixtures::archived(['title' => 'Example archived empty article', 'body_md' => '']);

    // The header action carries the same URL, so the empty-body link is the second one on the page.
    $emptyPage = Livewire::test(ViewKnowledgeArticle::class, ['record' => $empty->getRouteKey()])->assertSee('Článek zatím nemá žádný text.');
    $filledPage = Livewire::test(ViewKnowledgeArticle::class, ['record' => $filled->getRouteKey()]);
    $archivedPage = Livewire::test(ViewKnowledgeArticle::class, ['record' => $archivedEmpty->getRouteKey()])->assertSee('Článek zatím nemá žádný text.');

    expect(substr_count($emptyPage->html(), 'href="'.editArticleUrl($empty).'"'))->toBe(2)
        ->and(substr_count($filledPage->html(), 'href="'.editArticleUrl($filled).'"'))->toBe(1)
        ->and(substr_count($archivedPage->html(), 'href="'.editArticleUrl($archivedEmpty).'"'))->toBe(0);
});

it('fills the title, the body and the toggle from the article and shows its version under the heading', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example editable article', 'body_md' => 'Example stored body', 'sensitive' => true]);

    editArticlePage($article)
        ->assertFormSet(['title' => 'Example editable article', 'body' => 'Example stored body', 'sensitive' => true])
        ->assertSee('Verze 1');
});

it('saves a changed body as the next version, with its snapshot, and opens the view screen', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example editable article', 'body_md' => 'Example stored body']);

    editArticlePage($article)
        ->fillForm(['body' => 'Example changed body'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Článek byl uložen jako verze 2')
        ->assertRedirect(editArticleViewUrl($article));

    $stored = editArticleRow($article->getKey());
    $version = editArticleVersion($article->getKey(), 2);

    expect($stored->version_no)->toBe(2)
        ->and($stored->body_md)->toBe('Example changed body')
        ->and($version)->not->toBeNull()
        ->and($version?->title)->toBe('Example editable article')
        ->and($version?->body_md)->toBe('Example changed body')
        ->and(editArticleVersionCount($article->getKey()))->toBe(2);
});

it('names the save action Uložit změny, links the cancel action back to the view screen and lists no relation manager', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example editable article']);

    $page = editArticlePage($article)
        ->assertSee('Uložit změny')
        ->assertSee('Zrušit úpravy');

    expect($page->html())->toContain('href="'.editArticleViewUrl($article).'"')
        ->and($page->instance()->getRelationManagers())->toBe([]);
});

it('answers 404 on the edit URL of an archived article and opens the edit URL of an active one', function (): void {
    $active = KnowledgeFixtures::article(['title' => 'Example active article']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);

    $this->get(editArticleUrl($active))->assertOk();
    $this->get(editArticleUrl($archived))->assertNotFound();
});
