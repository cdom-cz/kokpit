<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Dom\HTMLDocument;

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
