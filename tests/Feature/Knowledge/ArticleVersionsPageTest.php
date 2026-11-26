<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeVersionRedaction;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewArticleVersion;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\KnowledgeHostileCorpus;

/*
 * The versions page of an article and the read-only view of one stored version (VER-02, D-08, D-10, D-12): every
 * version listed newest first with its markers, never loading a body for the list, and one version opened through
 * the one renderer. Every title and body is fictional.
 */

/**
 * An article with three versions: version 2 restored from version 1, version 1 carrying a redaction record, written
 * as system and without any reason text (only its length is stored).
 *
 * @param  array<string, mixed>  $attributes  column overrides of the article
 * @param  User|null  $author  the author of every version (a fresh admin when omitted)
 */
function articleVersionsPageArticle(array $attributes = [], ?User $author = null): KnowledgeArticle
{
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => "# Example first heading\n\nExample first body"],
        ['title' => 'Example second title', 'body' => 'Example second body', 'restored_from' => 1],
        ['title' => 'Example third title', 'body' => 'Example third body'],
    ], $attributes, $author);

    app(PartnerContext::class)->runAsSystem(static function () use ($article): void {
        $first = KnowledgeArticleVersion::query()->where('article_id', $article->getKey())->where('version_no', 1)->firstOrFail();

        (new KnowledgeVersionRedaction)->forceFill([
            'version_id' => $first->getKey(),
            'actor_id' => Canary::admin()->getKey(),
            'scope' => 'lines',
            'reason_length' => 12,
        ])->save();
    });

    return $article;
}

/**
 * The version rows of an article, oldest first, read as the system.
 *
 * @return Collection<int, KnowledgeArticleVersion>
 */
function articleVersionsPageRows(KnowledgeArticle $article): Collection
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): Collection => KnowledgeArticleVersion::query()->where('article_id', $article->getKey())->orderBy('version_no')->get(),
    );
}

/**
 * The versions page of an article as a Livewire test.
 */
function articleVersionsPageTest(KnowledgeArticle $article): Testable
{
    return Livewire::test(ListArticleVersions::class, ['record' => $article->getKey()]);
}

/**
 * The labels of the sub-navigation a page of the article shows.
 *
 * @return list<string>
 */
function articleVersionsPageSubNavigation(string $page, KnowledgeArticle $article): array
{
    $items = Livewire::test($page, ['record' => $article->getKey()])->instance()->getSubNavigation();

    return array_values(array_map(
        static fn (NavigationItem $item): string => (string) $item->getLabel(),
        array_filter($items, static fn (mixed $item): bool => $item instanceof NavigationItem),
    ));
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('lists the versions newest first with the current, restored and redacted markers, the author and the Prague time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));

    $author = Canary::admin();
    $article = articleVersionsPageArticle([], $author);
    $rows = articleVersionsPageRows($article);

    articleVersionsPageTest($article)
        ->assertCanSeeTableRecords([$rows[2], $rows[1], $rows[0]], inOrder: true)
        ->assertTableColumnStateSet('current', 'Aktuální', $rows[2])
        ->assertTableColumnStateNotSet('current', 'Aktuální', $rows[1])
        ->assertTableColumnStateNotSet('current', 'Aktuální', $rows[0])
        ->assertTableColumnStateSet('markers', ['Obnoveno z verze 1'], $rows[1])
        ->assertTableColumnStateSet('markers', ['Redigováno'], $rows[0])
        ->assertSee('Verze 3')
        ->assertSee($author->name)
        ->assertSee('20. 11. 2026 11:00');
});

it('titles the page with the article title', function (): void {
    $article = articleVersionsPageArticle();

    articleVersionsPageTest($article)->assertSee('Verze článku „Example third title“');
});

it('selects no body column for the list and offers no bulk action, no create action and no search', function (): void {
    $article = articleVersionsPageArticle();

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $component = articleVersionsPageTest($article)->assertSee('Example third title');
    $table = $component->instance()->getTable();

    $versionQueries = array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"knowledge_article_versions"') && str_starts_with(strtolower(ltrim($sql)), 'select'));

    expect($versionQueries)->not->toBe([])
        ->and(array_filter($versionQueries, static fn (string $sql): bool => str_contains($sql, 'body_md')))->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and($table->getHeaderActions())->toBe([])
        ->and($table->isSearchable())->toBeFalse()
        ->and($table->getDefaultPaginationPageOption())->toBe(25);
});

it('gives every icon row action a tooltip and an equal aria-label', function (): void {
    $article = articleVersionsPageArticle();

    $actions = articleVersionsPageTest($article)->instance()->getTable()->getRecordActions();

    expect($actions)->not->toBe([]);

    foreach ($actions as $action) {
        expect((string) $action->getTooltip())->not->toBe('')
            ->and($action->getExtraAttributes()['aria-label'] ?? null)->toBe($action->getTooltip());
    }
});

it('offers compare with previous on every row but version 1', function (): void {
    $article = articleVersionsPageArticle();
    $rows = articleVersionsPageRows($article);

    articleVersionsPageTest($article)
        ->assertTableActionHidden('compare_previous', $rows[0])
        ->assertTableActionVisible('compare_previous', $rows[1])
        ->assertTableActionVisible('compare_previous', $rows[2])
        ->assertTableActionVisible('view', $rows[0]);
});

it('carries a row link to the version view', function (): void {
    $article = articleVersionsPageArticle();
    $rows = articleVersionsPageRows($article);

    $url = articleVersionsPageTest($article)->instance()->getTable()->getRecordUrl($rows[1]);

    expect($url)->toBe(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 2]));
});

it('lists Zobrazit, Upravit and Verze in the sub-navigation, and no Upravit for an archived article', function (): void {
    $article = articleVersionsPageArticle();
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);

    expect(articleVersionsPageSubNavigation(ViewKnowledgeArticle::class, $article))->toBe(['Zobrazit', 'Upravit', 'Verze'])
        ->and(articleVersionsPageSubNavigation(EditKnowledgeArticle::class, $article))->toBe(['Zobrazit', 'Upravit', 'Verze'])
        ->and(articleVersionsPageSubNavigation(ListArticleVersions::class, $article))->toBe(['Zobrazit', 'Upravit', 'Verze'])
        ->and(articleVersionsPageSubNavigation(ViewKnowledgeArticle::class, $archived))->toBe(['Zobrazit', 'Verze'])
        ->and(articleVersionsPageSubNavigation(ListArticleVersions::class, $archived))->toBe(['Zobrazit', 'Verze']);
});

it('keeps Verze the active item on the version view', function (): void {
    expect(ListArticleVersions::getNavigationItemActiveRoutePattern())
        ->toContain(ListArticleVersions::getRouteName())
        ->toContain(ViewArticleVersion::getRouteName());
});

it('opens an old version read-only with the old and redacted callouts, the stored title and the rendered body', function (): void {
    $article = articleVersionsPageArticle();

    $content = (string) $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 1]))
        ->assertOk()
        ->assertSee('Verze 1')
        ->assertSee('Toto je starší verze. Aktuální je verze 3.')
        ->assertSee('Zobrazit aktuální verzi')
        ->assertSee('Tato verze byla redigována')
        ->assertSee('Example first title')
        ->assertDontSee('Článek je archivovaný')
        ->getContent();

    expect($content)->toContain(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 3]))
        ->and($content)->toMatch('/<div class="kokpit-prose">.*<h1[^>]*>Example first heading<\/h1>/s');
});

it('shows neither the old nor the redacted callout on the current version', function (): void {
    $article = articleVersionsPageArticle();

    $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 3]))
        ->assertOk()
        ->assertSee('Example third title')
        ->assertDontSee('Toto je starší verze')
        ->assertDontSee('Tato verze byla redigována');
});

it('shows the archived callout on a version of an archived article', function (): void {
    $article = articleVersionsPageArticle();

    app(PartnerContext::class)->runAsSystem(static function () use ($article): void {
        $article->delete();
    });

    $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 2]))
        ->assertOk()
        ->assertSee('Článek je archivovaný. Chcete-li verzi obnovit, nejdřív článek obnovte.');
});

it('renders a hostile body stored in a version exactly as the article view does, escaped', function (): void {
    $cases = array_values(KnowledgeHostileCorpus::cases());
    $article = KnowledgeFixtures::articleWithVersions(array_map(
        static fn (string $body): array => ['title' => 'Example hostile title', 'body' => $body],
        $cases,
    ));

    foreach ($cases as $index => $body) {
        $content = (string) $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => $index + 1]))
            ->assertOk()
            ->getContent();

        expect($content)->toContain(app(KnowledgeMarkdown::class)->render($body)->toHtml())
            ->not->toContain('<scr'.'ipt>window')
            ->not->toContain('<ifr'.'ame')
            ->not->toContain('<im'.'g src="x"');
    }
});

it('answers 404 for a version that does not exist or is not a number', function (): void {
    $article = articleVersionsPageArticle();

    foreach (['99', 'abc', '0', '1x', '-1', '99999999999999999999'] as $version) {
        $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => $version]))->assertNotFound();
    }
});

it('answers 404 for the version of another article', function (): void {
    $article = articleVersionsPageArticle();
    $other = KnowledgeFixtures::article(['title' => 'Example other article']);

    // The other article has version 1 only: version 3 of the first article must not open under it.
    $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $other, 'version' => 3]))->assertNotFound();
    $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 3]))->assertOk();
});
