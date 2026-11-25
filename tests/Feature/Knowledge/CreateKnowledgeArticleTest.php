<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use Filament\Facades\Filament;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Tabs;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\SecretFixtures;

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

    // The Html component between the tabs and the toggle is the loading indicator of the tab switch, not a field.
    $classes = array_values(array_filter(
        array_map(static fn (object $component): string => $component::class, $form->getComponents()),
        static fn (string $class): bool => $class !== Html::class,
    ));

    expect($classes)->toBe([TextInput::class, Tabs::class, Toggle::class]);
});

it('has no create-another action and names the submit and cancel actions in Czech', function (): void {
    $page = Livewire::test(CreateKnowledgeArticle::class)
        ->assertSee('Vytvořit článek')
        ->assertSee('Zrušit vytvoření')
        ->assertDontSee(__('filament-panels::resources/pages/create-record.form.actions.create_another.label'));

    expect($page->html())->toContain('href="'.KnowledgeArticleResource::getUrl('index').'"');
});

/**
 * A body with a NOTE callout, a table and a fenced php block.
 */
function createArticleRichBody(): string
{
    return implode("\n", [
        '# Example heading',
        '',
        '> [!NOTE]',
        '> Example callout text',
        '',
        '| Name | Value |',
        '| --- | --- |',
        '| alpha | 1 |',
        '',
        '```php',
        "echo 'example';",
        '```',
    ]);
}

/**
 * The prose wrapper as the view screen shows it for a stored body.
 */
function createArticleProse(string $storedBody): string
{
    return '<div class="kokpit-prose">'.app(KnowledgeMarkdown::class)->render($storedBody)->toHtml().'</div>';
}

/**
 * The source of a resource script with block and line comments removed, so a static check looks at code only.
 */
function createArticleScriptSource(string $relativePath): string
{
    $source = file_get_contents(resource_path($relativePath));
    expect($source)->toBeString()->not->toBe('');

    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    return (string) preg_replace('#^\s*//.*$#m', '', $source);
}

it('previews the unsaved text exactly as the view screen will show it, and keeps the typed text on the write tab', function (): void {
    $body = createArticleRichBody();
    $expected = createArticleProse(app(MarkdownNormaliser::class)->normalise($body));

    Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => 'Example preview', 'body' => $body])
        ->set('activeTab', 'preview')
        ->assertSeeHtml($expected)
        ->set('activeTab', 'write')
        ->assertSet('data.body', $body);

    expect(createArticleCount())->toBe(0);
});

it('shows in the preview what the view screen shows after the save, a prose hard break and trailing code spaces included', function (): void {
    // Double-quoted on purpose: no source line of this file ends in spaces.
    $body = "First prose line  \nSecond prose line\n\n```text\ncode line with trailing  \nnext code line\n```";
    $stored = app(MarkdownNormaliser::class)->normalise($body);
    $expected = createArticleProse($stored);

    expect($expected)->not->toContain('<br')
        ->and($stored)->toContain("code line with trailing  \n");

    $page = Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => 'Example parity', 'body' => $body])
        ->set('activeTab', 'preview')
        ->assertSeeHtml($expected);

    $page->call('create')->assertHasNoFormErrors();

    $article = createArticleByTitle('Example parity');

    expect($article->body_md)->toBe($stored);

    Livewire::test(ViewKnowledgeArticle::class, ['record' => $article->getRouteKey()])->assertSeeHtml($expected);
});

it('shows a muted note for an empty body in the preview', function (): void {
    Livewire::test(CreateKnowledgeArticle::class)
        ->set('activeTab', 'preview')
        ->assertSee('Není co zobrazit. Napište text na kartě Psaní.');
});

it('renders a text with a secret in the preview without a guard callout and stores nothing', function (): void {
    $body = SecretFixtures::bodyWith(SecretFixtures::githubToken(), 3);

    Livewire::test(CreateKnowledgeArticle::class)
        ->fillForm(['title' => 'Example secret preview', 'body' => $body])
        ->set('activeTab', 'preview')
        ->assertSeeHtml(createArticleProse(app(MarkdownNormaliser::class)->normalise($body)))
        ->assertDontSee('Text obsahuje tajný údaj');

    expect(createArticleCount())->toBe(0);
});

it('shows an over-long unsaved text as escaped plain text without running the normaliser or the Markdown parser', function (): void {
    $line = "Example **Example** prose line  \n";
    $body = str_repeat($line, intdiv(KnowledgeMarkdown::MAX_INPUT_LENGTH, strlen($line)) + 2);

    expect(mb_strlen($body))->toBeGreaterThan(KnowledgeMarkdown::MAX_INPUT_LENGTH);

    $plain = app(KnowledgeMarkdown::class)->render($body)->toHtml();
    $normalised = app(KnowledgeMarkdown::class)->render(app(MarkdownNormaliser::class)->normalise($body))->toHtml();

    expect($plain)->toContain('<p class="kokpit-prose__plain">')
        ->and($plain)->toContain('**Example**')
        ->and($plain)->not->toContain('<strong')
        // The normaliser would have trimmed the trailing spaces, so the two outputs differ.
        ->and($plain)->not->toBe($normalised);

    Livewire::test(CreateKnowledgeArticle::class)
        ->set('data.body', $body)
        ->set('activeTab', 'preview')
        ->assertSeeHtml('<div class="kokpit-prose">'.$plain.'</div>');

    expect(createArticleCount())->toBe(0);
});

it('renders a text of exactly the input limit as Markdown', function (): void {
    $lead = '**Example** ';
    $body = $lead.str_repeat('a', KnowledgeMarkdown::MAX_INPUT_LENGTH - strlen($lead));

    expect(mb_strlen($body))->toBe(KnowledgeMarkdown::MAX_INPUT_LENGTH);

    $expected = createArticleProse(app(MarkdownNormaliser::class)->normalise($body));

    expect($expected)->toContain('<strong>Example</strong>');

    Livewire::test(CreateKnowledgeArticle::class)
        ->set('data.body', $body)
        ->set('activeTab', 'preview')
        ->assertSeeHtml($expected);

    expect(createArticleCount())->toBe(0);
});

it('shows a loading indicator that targets the tab switch', function (): void {
    Livewire::test(CreateKnowledgeArticle::class)->assertSeeHtml('wire:loading.flex wire:target="activeTab"');
});

it('gives the editor the toolbar of D-07, no file attachments, no live updates and the task-list label', function (): void {
    $page = Livewire::test(CreateKnowledgeArticle::class)->assertSeeHtml('data-kokpit-task-list="Seznam úkolů"');

    $editors = array_values(array_filter(
        $page->instance()->form->getFlatComponents(),
        static fn (object $component): bool => $component instanceof MarkdownEditor,
    ));

    expect($editors)->toHaveCount(1);

    $editor = $editors[0];

    expect($editor->getToolbarButtons())->toBe([
        ['bold', 'italic', 'link'],
        ['heading'],
        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
        ['table'],
        ['undo', 'redo'],
    ])
        ->and($editor->hasFileAttachments())->toBeFalse()
        ->and($editor->isLive())->toBeFalse()
        ->and($editor->getMaxLength())->toBe(KnowledgeArticle::MAX_BODY_LENGTH);
});

it('registers the editor script, which edits through CodeMirror only and holds no markup or evaluation sink', function (): void {
    $script = null;

    foreach (FilamentAsset::getScripts(['app'], false) as $asset) {
        if ($asset->getId() === 'knowledge-editor') {
            $script = $asset;
        }
    }

    expect($script)->toBeInstanceOf(Js::class)
        ->and($script->getPath())->toBe(resource_path('js/knowledge-editor.js'))
        ->and($script->getRelativePublicPath())->toBe('js/app/knowledge-editor.js');

    $source = createArticleScriptSource('js/knowledge-editor.js');

    foreach (['_editor', 'codemirror', 'operation(', '- [ ] ', 'MutationObserver', 'livewire:navigated', 'kokpitKnowledgeEditorBound'] as $needle) {
        expect($source)->toContain($needle);
    }

    // Assembled from fragments so this list does not trip a scanner of its own.
    $sinks = [
        'inner'.'HTML', 'outer'.'HTML', 'inner'.'Text', 'insertAdjacent'.'HTML', 'document.'.'write', 'ev'.'al(',
        'new '.'Function', 'srcdoc',
    ];

    foreach ($sinks as $sink) {
        expect($source)->not->toContain($sink);
    }
});

it('warns about unsaved changes on the create page only, not panel-wide', function (): void {
    $page = Livewire::test(CreateKnowledgeArticle::class)->instance();

    expect((new ReflectionMethod($page, 'hasUnsavedDataChangesAlert'))->invoke($page))->toBeTrue()
        ->and(Filament::hasUnsavedChangesAlerts())->toBeFalse();
});
