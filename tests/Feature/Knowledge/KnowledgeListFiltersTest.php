<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The folder and tag columns, the AND tag filter and the empty states of the general knowledge list (D-04, D-05,
 * ORG-03). Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

/**
 * The list page, with the folder selected when an id is given.
 */
function knowledgeListFiltersPage(?string $folder = null): Testable
{
    return Livewire::withQueryParams(['folder' => $folder])->test(ListKnowledgeArticles::class);
}

/**
 * The id of the knowledge tag of the name.
 */
function knowledgeListFiltersTagId(string $name): string
{
    $tag = Tag::query()->where('type', TagType::Knowledge->value)->get()->first(static fn (Tag $tag): bool => (string) $tag->name === $name);

    return (string) $tag?->getKey();
}

/**
 * A tag of another type (a client tag) attached to the article, as a client would carry it.
 */
function knowledgeListFiltersAttachClientTag(KnowledgeArticle $article, string $name): Tag
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($article, $name): Tag {
        $article->attachTags([$name], TagType::Client->value);

        return Tag::query()->where('type', TagType::Client->value)->get()->first(static fn (Tag $tag): bool => (string) $tag->name === $name);
    });
}

it('offers the knowledge tags only in the tag filter, never a client tag', function (): void {
    $article = KnowledgeFixtures::taggedArticle(['Example server', 'Example backup']);
    knowledgeListFiltersAttachClientTag($article, 'Example client tag');

    $options = knowledgeListFiltersPage()->instance()->getTable()->getFilter('tags')->getOptions();

    expect(array_values($options))->toBe(['Example backup', 'Example server'])
        ->and(array_keys($options))->toContain(knowledgeListFiltersTagId('Example server'));
});

it('narrows the list to the articles carrying all chosen tags and names them in the indicator', function (): void {
    $both = KnowledgeFixtures::taggedArticle(['Example server', 'Example backup'], ['title' => 'Example both tags']);
    $one = KnowledgeFixtures::taggedArticle(['Example server'], ['title' => 'Example one tag']);

    knowledgeListFiltersPage()
        ->set('tableFilters.tags.values', [knowledgeListFiltersTagId('Example server'), knowledgeListFiltersTagId('Example backup')])
        ->assertCanSeeTableRecords([$both])
        ->assertCanNotSeeTableRecords([$one])
        ->assertSee('Všechny štítky: Example server, Example backup');
});

it('matches nothing for a forged filter value of a client tag or of no tag', function (): void {
    $article = KnowledgeFixtures::taggedArticle(['Example server'], ['title' => 'Example tagged']);
    $clientTag = knowledgeListFiltersAttachClientTag($article, 'Example client tag');

    knowledgeListFiltersPage()
        ->set('tableFilters.tags.values', [(string) $clientTag->getKey()])
        ->assertCanNotSeeTableRecords([$article]);

    // A value that is not an id is dropped before the query: the list stays whole and PostgreSQL never sees it.
    knowledgeListFiltersPage()
        ->set('tableFilters.tags.values', ['abc'])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$article]);
});

it('combines the folder, the tags and the archived switch', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');
    $other = KnowledgeFixtures::folder('Example other');
    $tags = ['Example server', 'Example backup'];

    $active = KnowledgeFixtures::taggedArticle($tags, ['title' => 'Example active', 'folder_id' => $folder->getKey()]);
    $archived = KnowledgeFixtures::taggedArticle($tags, ['title' => 'Example archived', 'folder_id' => $folder->getKey()]);
    $elsewhere = KnowledgeFixtures::taggedArticle($tags, ['title' => 'Example elsewhere', 'folder_id' => $other->getKey()]);

    app(PartnerContext::class)->runAsSystem(static function () use ($archived): void {
        $archived->delete();
    });

    $page = knowledgeListFiltersPage((string) $folder->getKey())
        ->set('tableFilters.tags.values', [knowledgeListFiltersTagId('Example server'), knowledgeListFiltersTagId('Example backup')])
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived, $elsewhere]);

    $page->set('tableFilters.show_archived.isActive', true)
        ->assertCanSeeTableRecords([$active, $archived])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

it('shows the folder path and at most three knowledge tag chips', function (): void {
    $root = KnowledgeFixtures::folder('Example ops');
    $child = KnowledgeFixtures::folder('Example servers', $root);

    $article = KnowledgeFixtures::taggedArticle(['Example one', 'Example two', 'Example three', 'Example four', 'Example five'], ['folder_id' => $child->getKey()]);
    knowledgeListFiltersAttachClientTag($article, 'Example client only');

    $page = knowledgeListFiltersPage()
        ->assertTableColumnStateSet('folder_path', 'Example ops / Example servers', $article)
        ->assertDontSee('Example client only');

    expect($page->instance()->getTable()->getColumn('tags')->getListLimit())->toBe(3);
});

it('reads the tags of the rows with a constant number of queries', function (): void {
    $count = static function (int $articles): int {
        foreach (range(1, $articles) as $number) {
            KnowledgeFixtures::taggedArticle(["Example tag {$number}"]);
        }

        // Warm the lookups that are not part of the rows, then count the tag reads of one render.
        Livewire::test(ListKnowledgeArticles::class);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Livewire::test(ListKnowledgeArticles::class)->assertSuccessful();

        return count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"taggables"')));
    };

    $few = $count(3);
    $many = $count(7);

    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});

it('shows the empty state of an empty folder with the create button for that folder', function (): void {
    $folder = KnowledgeFixtures::folder('Example empty');
    $id = (string) $folder->getKey();

    knowledgeListFiltersPage($id)
        ->assertSee('V této složce zatím nic není.')
        ->assertSee('Zařaďte sem článek v jeho úpravách nebo napište nový.')
        ->assertSeeHtml(KnowledgeArticleResource::getUrl('create', ['folder' => $id]));
});

it('shows the empty state of the unfiled articles', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');
    KnowledgeFixtures::article(['title' => 'Example filed article', 'folder_id' => $folder->getKey()]);

    knowledgeListFiltersPage('none')
        ->assertSee('Žádné nezařazené články.')
        ->assertSee('Články bez složky se zobrazí tady.');
});

it('shows the filtered empty state and clears the tags while the folder stays selected', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');
    KnowledgeFixtures::taggedArticle(['Example server'], ['title' => 'Example elsewhere']);
    $id = (string) $folder->getKey();

    knowledgeListFiltersPage($id)
        ->set('tableFilters.tags.values', [knowledgeListFiltersTagId('Example server')])
        ->assertSee('Žádný článek neodpovídá filtrům.')
        ->assertSee('Zrušit filtry')
        ->call('resetTableFiltersForm')
        ->assertSet('folder', $id)
        ->assertSet('tableFilters.tags.values', [])
        ->assertSee('V této složce zatím nic není.');
});

it('keeps the Phase 18 empty states when nothing is selected', function (): void {
    knowledgeListFiltersPage()
        ->assertSee('Zatím tu nejsou žádné články')
        ->set('tableFilters.show_archived.isActive', true)
        ->assertSee('Žádné archivované články');
});

it('shows the removable folder chip only while a real folder is selected', function (): void {
    $root = KnowledgeFixtures::folder('Example ops');
    KnowledgeFixtures::folder('Example servers', $root);
    $id = (string) $root->getKey();

    knowledgeListFiltersPage()->assertDontSee('(včetně podsložek)');
    knowledgeListFiltersPage('none')->assertDontSee('(včetně podsložek)');

    knowledgeListFiltersPage($id)
        ->assertSee('Složka: Example ops (včetně podsložek)')
        ->assertSeeHtml('aria-label="Zrušit filtr složky"')
        ->set('folder', null)
        ->assertDontSee('(včetně podsložek)');
});

it('links the new article button to the create screen with the selected folder only', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');
    $id = (string) $folder->getKey();

    knowledgeListFiltersPage($id)->assertSeeHtml(KnowledgeArticleResource::getUrl('create', ['folder' => $id]));

    knowledgeListFiltersPage()
        ->assertSeeHtml(KnowledgeArticleResource::getUrl('create'))
        ->assertDontSeeHtml('create?folder=');

    knowledgeListFiltersPage('none')->assertDontSeeHtml('create?folder=');
});
