<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The folder tree panel of the general knowledge list (D-01, D-02, ORG-03): the selection lives in the URL, a folder
 * lists its own articles and those of its subfolders, and a malformed value never breaks the page. Every name is
 * fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

/**
 * An article of the given title, in the folder or without one.
 */
function folderTreeListArticle(string $title, ?KnowledgeFolder $folder = null): KnowledgeArticle
{
    return KnowledgeFixtures::article(['title' => $title, 'folder_id' => $folder?->getKey()]);
}

/**
 * The list page opened with `?folder=` set to the value.
 */
function folderTreeListOpen(?string $folder): Testable
{
    return Livewire::withQueryParams(['folder' => $folder])->test(ListKnowledgeArticles::class);
}

/**
 * A root folder "Example ops" with the child "Example servers", and one article in each, one unfiled, one in
 * another root.
 *
 * @return array{root: KnowledgeFolder, child: KnowledgeFolder, other: KnowledgeFolder, articles: array<string, KnowledgeArticle>}
 */
function folderTreeListFixture(): array
{
    $root = KnowledgeFixtures::folder('Example ops');
    $child = KnowledgeFixtures::folder('Example servers', $root);
    $other = KnowledgeFixtures::folder('Example other');

    return [
        'root' => $root,
        'child' => $child,
        'other' => $other,
        'articles' => [
            'root' => folderTreeListArticle('Example root article', $root),
            'child' => folderTreeListArticle('Example child article', $child),
            'unfiled' => folderTreeListArticle('Example unfiled article'),
            'other' => folderTreeListArticle('Example other article', $other),
        ],
    ];
}

it('renders the Složky nav with both fixed entries and the folders, siblings in Czech order', function (): void {
    KnowledgeFixtures::folder('Example dům');
    KnowledgeFixtures::folder('Example čaj');
    KnowledgeFixtures::folder('Example cesta');

    Livewire::test(ListKnowledgeArticles::class)
        ->assertSeeHtml('aria-label="Složky"')
        ->assertSeeInOrder(['Všechny články', 'Bez složky', 'Example cesta', 'Example čaj', 'Example dům']);
});

it('lists the articles of a folder and of its subfolders, and nothing else', function (): void {
    $fixture = folderTreeListFixture();
    $articles = $fixture['articles'];

    folderTreeListOpen((string) $fixture['root']->getKey())
        ->assertCanSeeTableRecords([$articles['root'], $articles['child']])
        ->assertCanNotSeeTableRecords([$articles['unfiled'], $articles['other']]);

    folderTreeListOpen((string) $fixture['child']->getKey())
        ->assertCanSeeTableRecords([$articles['child']])
        ->assertCanNotSeeTableRecords([$articles['root'], $articles['unfiled'], $articles['other']]);
});

it('lists only the unfiled articles for ?folder=none', function (): void {
    $articles = folderTreeListFixture()['articles'];

    folderTreeListOpen('none')
        ->assertCanSeeTableRecords([$articles['unfiled']])
        ->assertCanNotSeeTableRecords([$articles['root'], $articles['child'], $articles['other']]);
});

it('lists every article without an error for a malformed, unknown or empty folder value', function (?string $value): void {
    $articles = folderTreeListFixture()['articles'];

    folderTreeListOpen($value)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(array_values($articles));
})->with([
    'not a uuid' => ['abc'],
    'a uuid of no folder' => [(string) Str::uuid()],
    'empty' => [''],
]);

it('opens the page from a plain request with a bad folder value', function (): void {
    folderTreeListFixture();

    $this->get(ListKnowledgeArticles::getUrl(['folder' => 'abc']))->assertOk();
    $this->get(ListKnowledgeArticles::getUrl(['folder' => (string) Str::uuid()]))->assertOk();
});

it('shows the rolled-up count of the active articles of a folder and its subfolders', function (): void {
    $fixture = folderTreeListFixture();

    // An archived article is not counted, as the table does not list it by default.
    KnowledgeFixtures::archived(['title' => 'Example archived article', 'folder_id' => $fixture['child']->getKey()]);

    $html = Livewire::test(ListKnowledgeArticles::class)->html();

    // In tree order: "Example ops" holds one article itself and one in its subfolder, "Example servers" one,
    // "Example other" one.
    preg_match_all('#<span class="kokpit-tree-count">(\d+)</span>#', $html, $counts);

    expect($counts[1])->toBe(['2', '1', '1']);
});

it('marks the selected row and expands the roots and the ancestors of the selection', function (): void {
    $three = KnowledgeFixtures::folderChain(3)[2];

    // Nothing selected: the root is open, the level below it closed.
    Livewire::test(ListKnowledgeArticles::class)
        ->assertSeeHtml('aria-label="Sbalit Example level 1"')
        ->assertSeeHtml('aria-label="Rozbalit Example level 2"')
        ->assertSeeHtml('aria-expanded="true"')
        ->assertSeeHtml('aria-expanded="false"');

    // The deepest folder selected: its ancestors are open and the row carries aria-current.
    $component = folderTreeListOpen((string) $three->getKey())
        ->assertSeeHtml('aria-label="Sbalit Example level 1"')
        ->assertSeeHtml('aria-label="Sbalit Example level 2"')
        ->assertSeeHtml('aria-current="true"');

    expect(substr_count($component->html(), 'aria-current="true"'))->toBe(1);
});

it('puts the selection in the URL and starts the table on its first page again', function (): void {
    $folder = KnowledgeFixtures::folder('Example ops');

    foreach (range(1, 12) as $number) {
        folderTreeListArticle("Example article {$number}");
    }

    $component = Livewire::test(ListKnowledgeArticles::class);
    $pageName = $component->instance()->getTablePaginationPageName();

    $component
        ->call('gotoPage', 2, $pageName)
        ->assertSet("paginators.{$pageName}", 2)
        ->set('folder', (string) $folder->getKey())
        ->assertSet("paginators.{$pageName}", 1)
        ->assertSet('folder', (string) $folder->getKey());
});

it('never selects the body and reads the tree with two queries', function (): void {
    $fixture = folderTreeListFixture();

    // Warm the lookups that are not part of the tree, then count.
    Livewire::test(ListKnowledgeArticles::class);

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    folderTreeListOpen((string) $fixture['root']->getKey())->assertSuccessful();

    $folderReads = array_filter($queries, static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'from "knowledge_folders"'));
    $countReads = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'count(*) as article_count'));
    $bodyReads = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'body_md'));

    expect($folderReads)->toHaveCount(1)
        ->and($countReads)->toHaveCount(1)
        ->and($bodyReads)->toBe([]);
});

it('keeps every selector of the stylesheet under the diff or tree root', function (): void {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kokpit-diff.css')));
    preg_match_all('/([^{}]+)\{/', $css, $matches);

    $selectors = [];

    foreach ($matches[1] as $header) {
        foreach (preg_split('/,(?![^(]*\))/', trim($header)) ?: [] as $selector) {
            if (! str_starts_with(trim($selector), '@media')) {
                $selectors[] = trim($selector);
            }
        }
    }

    $tree = array_filter($selectors, static fn (string $selector): bool => str_contains($selector, '.kokpit-tree'));

    expect(count($tree))->toBeGreaterThanOrEqual(5);

    foreach ($selectors as $selector) {
        expect(preg_match('/^(\.dark )?\.kokpit-(diff|tree)/', $selector))->toBe(1, "unscoped selector: {$selector}");
    }
});
