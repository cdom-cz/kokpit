<?php

declare(strict_types=1);

use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The read side of the Admin knowledge screens (KB-02, KB-03, D-06, D-13): the list of general articles with its
 * archived switch, the own view screen of every article and the place of the group in the menu. Every title and
 * body is fictional.
 */

/**
 * A moment as it is stored: UTC, so that the Prague wall clock of the screens is one hour later in winter.
 */
function knowledgeResourceMoment(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/**
 * A body with a heading, a table, a task list and a fenced code block.
 */
function knowledgeResourceRichBody(): string
{
    return implode("\n", [
        '# Example heading',
        '',
        '| Name | Value |',
        '| --- | --- |',
        '| alpha | 1 |',
        '',
        '- [x] Example done item',
        '- [ ] Example open item',
        '',
        '```php',
        "echo 'example';",
        '```',
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('lists the general articles with version and last edit, the newest edit first, and leaves other kinds out', function (): void {
    $older = KnowledgeFixtures::article(['title' => 'Example older article', 'version_no' => 3, 'edited_at' => knowledgeResourceMoment('2026-11-20 10:00:00')]);
    $newer = KnowledgeFixtures::article(['title' => 'Example newer article', 'edited_at' => knowledgeResourceMoment('2026-11-24 12:30:00')]);
    $incident = KnowledgeFixtures::article(['title' => 'Example incident article', 'kind' => ArticleKind::Incident]);

    Livewire::test(ListKnowledgeArticles::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords([$incident])
        ->assertSee('Example newer article')
        ->assertTableColumnStateSet('version_no', 3, $older)
        ->assertTableColumnStateSet('version_no', 1, $newer)
        ->assertTableColumnFormattedStateSet('edited_at', '20. 11. 2026 11:00', $older)
        ->assertTableColumnFormattedStateSet('edited_at', '24. 11. 2026 13:30', $newer);
});

it('selects no body column for the list and offers no bulk action and no search', function (): void {
    KnowledgeFixtures::article(['title' => 'Example listed article', 'body_md' => 'Example body text']);

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $component = Livewire::test(ListKnowledgeArticles::class)->assertSee('Example listed article');

    $listQueries = array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"knowledge_articles"') && str_starts_with(strtolower(ltrim($sql)), 'select'));
    $table = $component->instance()->getTable();

    expect($listQueries)->not->toBe([])
        ->and(array_filter($listQueries, static fn (string $sql): bool => str_contains($sql, 'body_md')))->toBe([])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and($table->isSearchable())->toBeFalse();
});

it('marks a sensitive article with the lock icon and no other article', function (): void {
    $sensitive = KnowledgeFixtures::article(['title' => 'Example sensitive article', 'sensitive' => true]);
    $plain = KnowledgeFixtures::article(['title' => 'Example plain article']);

    $component = Livewire::test(ListKnowledgeArticles::class)
        ->assertTableColumnStateSet('sensitive', true, $sensitive)
        ->assertTableColumnStateSet('sensitive', false, $plain);

    $column = $component->instance()->getTable()->getColumn('sensitive');

    expect($column->getIcon(true))->toBe(Heroicon::OutlinedLockClosed)
        ->and($column->getIcon(false))->toBeNull()
        ->and($column->getTooltip(true))->toBe('Citlivý článek')
        ->and($column->getTooltip(false))->toBeNull();
});

it('clips a long title to one line and shows the full title as the tooltip', function (): void {
    $title = str_repeat('Long example title ', 10);
    $title = mb_substr($title, 0, KnowledgeArticle::MAX_TITLE_LENGTH);
    $article = KnowledgeFixtures::article(['title' => $title]);

    $column = Livewire::test(ListKnowledgeArticles::class)
        ->assertCanSeeTableRecords([$article])
        ->instance()->getTable()->getColumn('title');

    expect(mb_strlen($title))->toBe(200)
        ->and($column->getLineClamp())->toBe(1)
        ->and($column->getTooltip($title))->toBe($title);
});

it('shows the empty state of an empty list', function (): void {
    Livewire::test(ListKnowledgeArticles::class)
        ->assertSee('Zatím tu nejsou žádné články')
        ->assertSee('Napište první článek, třeba postup nebo poznámky k nastavení.');
});

it('shows the title, the meta line and the body rendered by the one renderer on the view screen', function (): void {
    $body = knowledgeResourceRichBody();
    $article = KnowledgeFixtures::article([
        'title' => 'Example readable article',
        'body_md' => $body,
        'edited_at' => knowledgeResourceMoment('2026-11-25 13:05:00'),
    ]);

    $expected = app(KnowledgeMarkdown::class)->render($body)->toHtml();

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->assertSee('Example readable article')
        ->assertSee('Verze 1')
        ->assertSee('Naposledy upraveno 25. 11. 2026 14:05')
        ->assertDontSee('Citlivý')
        ->assertDontSee('Archivováno')
        ->assertSeeHtml('<div class="kokpit-prose">'.$expected.'</div>');
});

it('shows the sensitive badge on the view screen of a sensitive article', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example sensitive article', 'body_md' => 'Example text', 'sensitive' => true]);

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->assertSee('Citlivý');
});

it('shows a muted note instead of the body wrapper for an article without a body', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example title only article', 'body_md' => '']);

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->assertSee('Example title only article')
        ->assertSee('Článek zatím nemá žádný text.')
        ->assertDontSeeHtml('class="kokpit-prose"');
});

it('does not open an article of another kind on this resource', function (): void {
    $incident = KnowledgeFixtures::article(['title' => 'Example incident article', 'kind' => ArticleKind::Incident]);

    $this->get(KnowledgeArticleResource::getUrl('view', ['record' => $incident->getKey()]))->assertNotFound();
});

it('is not globally searchable', function (): void {
    expect(KnowledgeArticleResource::canGloballySearch())->toBeFalse();
});
