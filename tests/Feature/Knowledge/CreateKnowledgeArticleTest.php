<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The create screen of a general knowledge article (KB-01, KB-06, SEC-01 to SEC-03, D-01, D-03, D-06 to D-08): the
 * create flow, the preview tab, the toolbar, the leave warning and the secret-guard feedback. Every text is
 * fictional; secret-shaped samples come only from SecretFixtures, assembled at runtime.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function createArticleSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The number of stored articles, archived ones included.
 */
function createArticleCount(): int
{
    return createArticleSystem(static fn (): int => KnowledgeArticle::query()->withTrashed()->count());
}

/**
 * The stored article with the given title.
 */
function createArticleByTitle(string $title): KnowledgeArticle
{
    return createArticleSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->where('title', $title)->firstOrFail());
}

/**
 * A body with a heading, a prose line and a fenced bash block.
 */
function createArticleBody(): string
{
    return implode("\n", [
        '# Example heading',
        '',
        'Example paragraph about the setup.',
        '',
        '```bash',
        'ls -la',
        '```',
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(Canary::admin());
});

it('offers the create screen from the header of the list and, with no article, from the empty state', function (): void {
    $createUrl = KnowledgeArticleResource::getUrl('create');

    $empty = Livewire::test(ListKnowledgeArticles::class)->assertSee('Nový článek');

    expect(substr_count($empty->html(), 'href="'.$createUrl.'"'))->toBe(2);

    KnowledgeFixtures::article(['title' => 'Example listed article']);

    $filled = Livewire::test(ListKnowledgeArticles::class)->assertSee('Nový článek');

    expect(substr_count($filled->html(), 'href="'.$createUrl.'"'))->toBe(1);
});

it('creates an article as version 1 and opens its view screen', function (): void {
    $body = createArticleBody();

    $page = Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => 'Example runbook', 'body' => $body, 'sensitive' => false])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Článek byl uložen jako verze 1');

    $article = createArticleByTitle('Example runbook');
    $versions = createArticleSystem(static fn (): int => KnowledgeArticleVersion::query()->where('article_id', $article->getKey())->count());

    $page->assertRedirect(KnowledgeArticleResource::getUrl('view', ['record' => $article]));

    expect($article->version_no)->toBe(1)
        ->and($article->body_md)->toBe(app(MarkdownNormaliser::class)->normalise($body))
        ->and($article->sensitive)->toBeFalse()
        ->and($versions)->toBe(1);
});

it('refuses a blank title with its own message and stores nothing', function (): void {
    Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => '', 'body' => createArticleBody()])
        ->call('create')
        ->assertHasFormErrors(['title' => 'required'])
        ->assertSee('Vyplňte název článku.');

    expect(createArticleCount())->toBe(0);
});

it('refuses a body longer than the limit and stores nothing', function (): void {
    Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => 'Example long article', 'body' => str_repeat('a', KnowledgeArticle::MAX_BODY_LENGTH + 1)])
        ->call('create')
        ->assertHasFormErrors(['body']);

    expect(createArticleCount())->toBe(0);
});

it('builds the form as title, tabs and the sensitive toggle', function (): void {
    $form = Livewire::test(CreateKnowledgeArticle::class)->instance()->form;

    $classes = array_map(static fn (object $component): string => $component::class, $form->getComponents());

    expect($classes)->toBe([TextInput::class, Tabs::class, Toggle::class]);
});

it('has no create-another action and names the submit and cancel actions in Czech', function (): void {
    $page = Livewire::test(CreateKnowledgeArticle::class)
        ->assertSee('Vytvořit článek')
        ->assertSee('Zrušit vytvoření')
        ->assertDontSee(__('filament-panels::resources/pages/create-record.form.actions.create_another.label'));

    expect($page->html())->toContain('href="'.KnowledgeArticleResource::getUrl('index').'"');
});
