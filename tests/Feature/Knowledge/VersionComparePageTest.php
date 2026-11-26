<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeVersionRedaction;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CompareArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewArticleVersion;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The compare page of two stored versions (VER-04, D-09, D-12): the two versions come from the URL, the diff is the
 * one diff view, the title is compared apart, and every malformed or unknown number answers 404. Every title and body
 * is fictional.
 */

/**
 * An article with three versions: the second changes the title and one body line, the third repeats the second.
 */
function versionComparePageArticle(): KnowledgeArticle
{
    return KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example old title', 'body' => "Example shared line\nExample old line"],
        ['title' => 'Example new title', 'body' => "Example shared line\nExample new line"],
        ['title' => 'Example new title', 'body' => "Example shared line\nExample new line"],
    ]);
}

/**
 * The compare page of an article as a Livewire test, with the given query string.
 *
 * @param  array<string, string>  $query
 */
function versionComparePageTest(KnowledgeArticle $article, array $query = []): Livewire\Features\SupportTesting\Testable
{
    return Livewire::withQueryParams($query)->test(CompareArticleVersions::class, ['record' => $article->getKey()]);
}

/**
 * The URL of the compare page of an article, with the given query string.
 *
 * @param  array<string, string>  $query
 */
function versionComparePageUrl(KnowledgeArticle $article, array $query = []): string
{
    $url = KnowledgeArticleResource::getUrl('compare', ['record' => $article]);

    return $query === [] ? $url : $url.'?'.http_build_query($query);
}

/**
 * Runs a forged Livewire update and expects the 404 of the page: either the exception reaches the test or the test
 * response carries the status.
 *
 * @param  Closure(): Livewire\Features\SupportTesting\Testable  $update
 */
function versionComparePageExpectNotFound(Closure $update): void
{
    try {
        $update()->assertStatus(404);
    } catch (NotFoundHttpException) {
        expect(true)->toBeTrue();
    }
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('compares the current version with its predecessor when no parameter is given', function (): void {
    $article = versionComparePageArticle();

    versionComparePageTest($article)
        ->assertSet('from', '2')
        ->assertSet('to', '3')
        ->assertSee('Obě verze mají stejný text.');
});

it('compares the chosen pair, in either order', function (): void {
    $article = versionComparePageArticle();

    $forward = versionComparePageTest($article, ['from' => '1', 'to' => '3'])
        ->assertSet('from', '1')
        ->assertSet('to', '3')
        ->assertSee('Přidáno 1, odstraněno 1 řádků');

    expect($forward->html())->toMatch('/kokpit-diff__row--removed.*?Example old line/s')
        ->and($forward->html())->toMatch('/kokpit-diff__row--added.*?Example new line/s');

    $reverse = versionComparePageTest($article, ['from' => '3', 'to' => '1'])
        ->assertSee('Přidáno 1, odstraněno 1 řádků');

    expect($reverse->html())->toMatch('/kokpit-diff__row--removed.*?Example new line/s')
        ->and($reverse->html())->toMatch('/kokpit-diff__row--added.*?Example old line/s');
});

it('takes the current version as the target and the version before the target as the source when one is missing', function (): void {
    $article = versionComparePageArticle();

    versionComparePageTest($article, ['from' => '1'])->assertSet('from', '1')->assertSet('to', '3');
    versionComparePageTest($article, ['to' => '2'])->assertSet('from', '1')->assertSet('to', '2');
    versionComparePageTest($article, ['to' => '1'])->assertSet('from', '1')->assertSet('to', '1');
});

it('shows the line numbers and the two title lines when the title changed, and the muted line when it did not', function (): void {
    $article = versionComparePageArticle();

    $changed = versionComparePageTest($article, ['from' => '1', 'to' => '2'])
        ->assertSee('Example old title')
        ->assertSee('Example new title')
        ->assertDontSee('Název se nezměnil');

    expect($changed->html())->toContain('kokpit-diff__number');

    versionComparePageTest($article, ['from' => '2', 'to' => '3'])
        ->assertSee('Název se nezměnil: Example new title');
});

it('shows its own state for the same version on both sides and no diff table', function (): void {
    $article = versionComparePageArticle();

    $component = versionComparePageTest($article, ['from' => '2', 'to' => '2'])
        ->assertSee('Vybrali jste stejnou verzi. Zvolte dvě různé verze.');

    expect($component->html())->not->toContain('kokpit-diff__scroll');
});

it('shows the identical state for two versions with equal text', function (): void {
    $article = versionComparePageArticle();

    $component = versionComparePageTest($article, ['from' => '2', 'to' => '3'])
        ->assertSee('Obě verze mají stejný text.');

    expect($component->html())->not->toContain('kokpit-diff__scroll');
});

it('recomputes the diff when a select changes', function (): void {
    $article = versionComparePageArticle();

    $component = versionComparePageTest($article, ['from' => '2', 'to' => '3'])
        ->assertSee('Obě verze mají stejný text.')
        ->set('from', '1')
        ->assertSet('from', '1')
        ->assertSee('Přidáno 1, odstraněno 1 řádků');

    expect($component->html())->toContain('Example old line');
});

it('lists the versions newest first in both selects, with the live binding and the loading indicator', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));

    $article = versionComparePageArticle();

    $component = versionComparePageTest($article)
        ->assertSeeInOrder(['Verze 3 · 20. 11. 2026 11:00', 'Verze 2 · 20. 11. 2026 11:00', 'Verze 1 · 20. 11. 2026 11:00'])
        ->assertSee('Z verze')
        ->assertSee('Na verzi');

    expect(substr_count($component->html(), 'wire:model.live'))->toBeGreaterThanOrEqual(2)
        ->and($component->html())->toContain('wire:target="from,to"');
});

it('answers 404 for a number that is unknown, malformed or forged', function (): void {
    $article = versionComparePageArticle();

    foreach ([['to' => '9'], ['from' => 'x'], ['from' => '0'], ['to' => '-1'], ['from' => '1x'], ['to' => ''], ['from' => '99999999999999999999']] as $query) {
        $this->get(versionComparePageUrl($article, $query))->assertNotFound();
    }

    $this->get(versionComparePageUrl($article).'?from[]=1')->assertNotFound();
    $this->get(versionComparePageUrl($article, ['from' => '1', 'to' => '3']))->assertOk();
});

it('answers 404 when a Livewire update sets a number that is unknown or malformed', function (): void {
    $article = versionComparePageArticle();

    foreach (['99', '0', 'abc', '-1'] as $value) {
        versionComparePageExpectNotFound(static fn () => versionComparePageTest($article, ['from' => '1', 'to' => '2'])->set('from', $value));
        versionComparePageExpectNotFound(static fn () => versionComparePageTest($article, ['from' => '1', 'to' => '2'])->set('to', $value));
    }
});

it('answers 404 for a number that belongs to another article', function (): void {
    $article = versionComparePageArticle();
    $other = KnowledgeFixtures::article(['title' => 'Example other article']);

    // The other article has version 1 only.
    $this->get(versionComparePageUrl($other, ['from' => '1', 'to' => '3']))->assertNotFound();
    $this->get(versionComparePageUrl($article, ['from' => '1', 'to' => '3']))->assertOk();
});

it('shows the stored redacted text of a version, never an original', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example title', 'body' => "Example shared line\n[REDIGOVÁNO]"],
        ['title' => 'Example title', 'body' => "Example shared line\nExample later line"],
    ]);

    app(PartnerContext::class)->runAsSystem(static function () use ($article): void {
        $first = KnowledgeArticleVersion::query()->where('article_id', $article->getKey())->where('version_no', 1)->firstOrFail();

        (new KnowledgeVersionRedaction)->forceFill([
            'version_id' => $first->getKey(),
            'actor_id' => Canary::admin()->getKey(),
            'scope' => 'lines',
            'reason_length' => 12,
        ])->save();
    });

    versionComparePageTest($article, ['from' => '1', 'to' => '2'])
        ->assertSee('[REDIGOVÁNO]')
        ->assertSee('Example later line');
});

it('reads the bodies of the two compared versions only', function (): void {
    $article = versionComparePageArticle();

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    versionComparePageTest($article, ['from' => '1', 'to' => '3'])->assertSee('Přidáno 1, odstraněno 1 řádků');

    $bodyQueries = array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, '"knowledge_article_versions"') && str_contains($sql, 'body_md'),
    ));

    expect($bodyQueries)->toHaveCount(1)
        ->and($bodyQueries[0])->toContain('"version_no" in (');
});

it('keeps the compare path apart from the version path', function (): void {
    $article = versionComparePageArticle();

    $comparePath = (string) parse_url(versionComparePageUrl($article), PHP_URL_PATH);
    $versionPath = (string) parse_url(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 2]), PHP_URL_PATH);

    expect(Route::getRoutes()->match(Request::create($comparePath))->getName())->toBe(CompareArticleVersions::getRouteName())
        ->and(Route::getRoutes()->match(Request::create($versionPath))->getName())->toBe(ViewArticleVersion::getRouteName())
        ->and(ListArticleVersions::getNavigationItemActiveRoutePattern())->toContain(CompareArticleVersions::getRouteName());
});

it('offers compare with previous on every version but the first, on the versions page and the version view', function (): void {
    $article = versionComparePageArticle();

    $actions = Livewire::test(ListArticleVersions::class, ['record' => $article->getKey()])->instance()->getTable()->getRecordActions();

    expect(array_map(static fn ($action): string => $action->getName(), $actions))->toContain('compare_previous');

    $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 2]))
        ->assertOk()
        ->assertSee('Porovnat s předchozí')
        ->assertSee(htmlspecialchars(versionComparePageUrl($article, ['from' => '1', 'to' => '2']), ENT_QUOTES), false);

    $this->get(KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => 1]))
        ->assertOk()
        ->assertDontSee('Porovnat s předchozí');
});
