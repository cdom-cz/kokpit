<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Dom\HTMLDocument;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;

/*
 * This file pins what only the application container can prove about the knowledge renderer: the Czech labels the
 * service provider binds into it (callouts, Copy button, language announcement, task state), and that the prose
 * assets are registered. Every snippet is fictional.
 */

function knowledgeRenderingDom(string $markdown): HTMLDocument
{
    $html = (string) app(KnowledgeMarkdown::class)->render($markdown);

    return HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);
}

it('resolves the renderer as one shared instance', function (): void {
    expect(app(KnowledgeMarkdown::class))->toBe(app(KnowledgeMarkdown::class));
});

it('labels each callout type with its Czech name', function (string $type, string $label): void {
    $dom = knowledgeRenderingDom("> [!{$type}]\n> Example note");
    $callout = $dom->querySelector('div.kokpit-callout');

    expect($callout)->not->toBeNull()
        ->and($callout->querySelector('.kokpit-callout__label')?->textContent)->toBe($label)
        ->and($callout->textContent)->toContain('Example note');
})->with([
    'note' => ['NOTE', 'Poznámka'],
    'tip' => ['TIP', 'Tip'],
    'important' => ['IMPORTANT', 'Důležité'],
    'warning' => ['WARNING', 'Varování'],
    'caution' => ['CAUTION', 'Pozor'],
]);

it('renders the Czech Copy button labels and announces the language of a known block', function (): void {
    $dom = knowledgeRenderingDom("```bash\necho example\n```");
    $button = $dom->querySelector('[data-kokpit-copy]');

    expect($button)->not->toBeNull()
        ->and($button->textContent)->toBe('Kopírovat kód')
        ->and($button->getAttribute('data-label-copied'))->toBe('Zkopírováno')
        ->and($button->getAttribute('data-label-failed'))->toBe('Kopírování se nezdařilo')
        ->and($dom->querySelector('pre')?->getAttribute('aria-label'))->toBe('Kód: bash');
});

it('announces a block of an unknown language without a language name', function (): void {
    $dom = knowledgeRenderingDom("```brainfuck\n+++\n```");

    expect($dom->querySelector('pre')?->getAttribute('aria-label'))->toBe('Kód');
});

it('prefixes task items with their Czech state', function (): void {
    $dom = knowledgeRenderingDom("- [x] Example done\n- [ ] Example open");
    $text = $dom->body?->textContent ?? '';

    expect($text)->toContain('Hotovo:')->toContain('Nehotovo:');
});

/**
 * The asset with the given id from a list of Filament assets, or null.
 *
 * @param  array<int, Asset>  $assets
 */
function knowledgeRenderingAsset(array $assets, string $id): ?Asset
{
    foreach ($assets as $asset) {
        if ($asset->getId() === $id) {
            return $asset;
        }
    }

    return null;
}

/**
 * The content of a resource file with comments removed (block comments, and line comments of a script), so a static
 * check looks at code only.
 */
function knowledgeRenderingSource(string $relativePath): string
{
    $source = file_get_contents(resource_path($relativePath));
    expect($source)->toBeString()->not->toBe('');

    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    return str_ends_with($relativePath, '.js') ? (string) preg_replace('#^\s*//.*$#m', '', $source) : $source;
}

it('registers the prose stylesheet and the copy script as Filament assets of the app package', function (): void {
    $style = knowledgeRenderingAsset(FilamentAsset::getStyles(['app']), 'kokpit-prose');
    $script = knowledgeRenderingAsset(FilamentAsset::getScripts(['app'], false), 'knowledge-copy');

    expect($style)->toBeInstanceOf(Css::class)
        ->and($style->getPackage())->toBe('app')
        ->and($style->getPath())->toBe(resource_path('css/kokpit-prose.css'))
        ->and($style->getRelativePublicPath())->toBe('css/app/kokpit-prose.css')
        ->and($script)->toBeInstanceOf(Js::class)
        ->and($script->getPackage())->toBe('app')
        ->and($script->getPath())->toBe(resource_path('js/knowledge-copy.js'))
        ->and($script->getRelativePublicPath())->toBe('js/app/knowledge-copy.js')
        ->and(resource_path('css/kokpit-prose.css'))->toBeFile()
        ->and(resource_path('js/knowledge-copy.js'))->toBeFile();
});

it('keeps the copy script on text content and data attributes only', function (): void {
    $script = knowledgeRenderingSource('js/knowledge-copy.js');

    foreach ([
        'textContent', 'navigator.clipboard.writeText', '[data-kokpit-copy]', '.kokpit-code', 'pre code',
        'labelCopied', 'labelFailed', 'labelDefault', 'kokpitKnowledgeCopyBound', '2000', 'document.createRange',
        'window.getSelection',
    ] as $needle) {
        expect($script)->toContain($needle);
    }

    // The HTML-reading and HTML-writing element properties, the insertion sink and the evaluation forms are the sinks
    // through which author-controlled text could become markup or code; none may appear. Assembled from fragments so
    // this list does not trip a scanner of its own.
    $sinks = [
        'inner'.'HTML', 'outer'.'HTML', 'inner'.'Text', 'insertAdjacent'.'HTML', 'document.'.'write', 'ev'.'al(',
        'new '.'Function', 'srcdoc',
    ];

    foreach ($sinks as $sink) {
        expect($script)->not->toContain($sink);
    }
});

it('scopes every selector of the prose stylesheet under the prose wrapper', function (): void {
    $css = knowledgeRenderingSource('css/kokpit-prose.css');
    preg_match_all('/([^{}]+)\{/', $css, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $header) {
        $header = trim($header);

        if (str_starts_with($header, '@')) {
            continue;
        }

        // Split on the commas between selectors, not on those inside :is(...) or :where(...).
        foreach (preg_split('/,(?![^(]*\))/', $header) ?: [] as $selector) {
            $selector = trim($selector);

            expect($selector === '.kokpit-prose' || str_starts_with($selector, '.kokpit-prose ') || str_starts_with($selector, '.dark .kokpit-prose'))
                ->toBeTrue("unscoped selector: {$selector}");
        }
    }
});

it('limits the prose stylesheet to the agreed type scale and the two keyword colours', function (): void {
    $css = knowledgeRenderingSource('css/kokpit-prose.css');

    preg_match_all('/font-size:\s*([^;}]+)/', $css, $sizes);
    preg_match_all('/font-weight:\s*([^;}]+)/', $css, $weights);

    expect($sizes[1])->not->toBeEmpty()->and($weights[1])->not->toBeEmpty();

    foreach ($sizes[1] as $size) {
        expect(trim($size))->toBeIn(['28px', '20px', '16px', '14px']);
    }

    foreach ($weights[1] as $weight) {
        expect(trim($weight))->toBeIn(['400', '600']);
    }

    // A hex colour literal may only be the value of the keyword token property (Filament has no violet variable).
    preg_match_all('/(--[a-z-]+):\s*#[0-9a-fA-F]{3,8}\b/', $css, $hexDeclarations);
    $allHex = preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css);

    expect($allHex)->toBe(2)->and($hexDeclarations[1])->toBe(['--kp-token-keyword', '--kp-token-keyword']);
});

it('lets code blocks and tables scroll inside their own wrapper and wraps long words', function (): void {
    $css = knowledgeRenderingSource('css/kokpit-prose.css');

    expect($css)->toContain('overflow-wrap: anywhere')
        ->toContain('max-width: 48rem')
        ->toMatch('/\.kokpit-prose \.kokpit-code pre\s*\{[^}]*overflow-x:\s*auto/')
        ->toMatch('/\.kokpit-prose table\s*\{[^}]*display:\s*block[^}]*\}/')
        ->toMatch('/\.kokpit-prose table\s*\{[^}]*overflow-x:\s*auto[^}]*\}/')
        ->toMatch('/\.kokpit-prose \.kokpit-prose__plain\s*\{[^}]*white-space:\s*pre-wrap/');
});
