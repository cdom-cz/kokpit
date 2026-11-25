<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Dom\HTMLDocument;

/*
 * This file pins the callout contract of the knowledge renderer (KB-04, D-12): a top-level block quote whose first
 * line is exactly one of the five GitHub alert markers becomes a labelled callout block that keeps its own Markdown
 * content; everything else (unknown type, text after the marker, a callout inside a quote or inside another callout)
 * stays an ordinary block quote. Unit tests run without the application, so the renderer gets an identity
 * translator. Every snippet is fictional.
 */

function calloutDom(string $markdown): HTMLDocument
{
    $renderer = new KnowledgeMarkdown(
        static fn (string $key, array $replace = []): string => $key.($replace === [] ? '' : '|'.implode('|', $replace)),
    );

    return HTMLDocument::createFromString(
        '<!DOCTYPE html><html><body>'.$renderer->render($markdown).'</body></html>',
        LIBXML_NOERROR,
    );
}

it('renders each of the five alert types as a labelled callout that keeps its content', function (string $type): void {
    $lower = strtolower($type);
    $dom = calloutDom("> [!{$type}]\n> Example text with `code` inside.\n>\n> - first item\n> - second item");
    $callout = $dom->querySelector('div.kokpit-callout');

    expect($callout)->not->toBeNull()
        ->and($callout->getAttribute('class'))->toBe("kokpit-callout kokpit-callout--{$lower}")
        ->and($callout->firstElementChild?->nodeName)->toBe('P')
        ->and($callout->firstElementChild?->getAttribute('class'))->toBe('kokpit-callout__label')
        ->and($callout->firstElementChild?->textContent)->toBe("callouts.{$lower}")
        ->and($dom->querySelectorAll('blockquote'))->toHaveCount(0)
        ->and($callout->querySelector('p:not(.kokpit-callout__label)')?->textContent)->toContain('Example text with')
        ->and($callout->querySelector('p code')?->textContent)->toBe('code')
        ->and($callout->querySelectorAll('li'))->toHaveCount(2)
        ->and($callout->textContent)->not->toContain("[!{$type}]");
})->with(['NOTE', 'TIP', 'IMPORTANT', 'WARNING', 'CAUTION']);

it('accepts the alert type in any letter case', function (string $marker, string $class): void {
    $dom = calloutDom("> {$marker}\n> Example text.");

    expect($dom->querySelector('div.kokpit-callout')?->getAttribute('class'))->toBe($class)
        ->and($dom->querySelectorAll('blockquote'))->toHaveCount(0);
})->with([
    'lower case' => ['[!note]', 'kokpit-callout kokpit-callout--note'],
    'mixed case' => ['[!Tip]', 'kokpit-callout kokpit-callout--tip'],
    'upper case' => ['[!CAUTION]', 'kokpit-callout kokpit-callout--caution'],
]);

it('keeps an unknown type as an ordinary block quote', function (): void {
    $dom = calloutDom("> [!FOO]\n> Example text.");

    expect($dom->querySelectorAll('div.kokpit-callout'))->toHaveCount(0)
        ->and($dom->querySelectorAll('blockquote'))->toHaveCount(1)
        ->and($dom->querySelector('blockquote')?->textContent)->toContain('[!FOO]');
});

it('keeps a marker followed by text on the same line as an ordinary block quote', function (): void {
    $dom = calloutDom('> [!WARNING] same line text');

    expect($dom->querySelectorAll('div.kokpit-callout'))->toHaveCount(0)
        ->and($dom->querySelectorAll('blockquote'))->toHaveCount(1)
        ->and($dom->querySelector('blockquote')?->textContent)->toContain('same line text');
});

it('keeps a callout marker inside a block quote or inside another callout as a block quote', function (): void {
    $inQuote = calloutDom("> Outer text.\n>\n> > [!NOTE]\n> > Inner text.");

    expect($inQuote->querySelectorAll('div.kokpit-callout'))->toHaveCount(0)
        ->and($inQuote->querySelectorAll('blockquote'))->toHaveCount(2);

    $inCallout = calloutDom("> [!TIP]\n> Outer text.\n>\n> > [!NOTE]\n> > Inner text.");
    $outer = $inCallout->querySelector('div.kokpit-callout');

    expect($inCallout->querySelectorAll('div.kokpit-callout'))->toHaveCount(1)
        ->and($outer?->getAttribute('class'))->toBe('kokpit-callout kokpit-callout--tip')
        ->and($outer?->querySelectorAll('blockquote'))->toHaveCount(1)
        ->and($outer?->querySelector('blockquote')?->textContent)->toContain('[!NOTE]');
});

it('keeps a quote that merely starts with ordinary text as a block quote', function (): void {
    $dom = calloutDom("> Example text.\n> [!NOTE]");

    expect($dom->querySelectorAll('div.kokpit-callout'))->toHaveCount(0)
        ->and($dom->querySelectorAll('blockquote'))->toHaveCount(1);
});

it('renders a callout that holds only the marker line as a label without an empty paragraph', function (): void {
    $dom = calloutDom('> [!NOTE]');
    $callout = $dom->querySelector('div.kokpit-callout');

    expect($callout)->not->toBeNull()
        ->and($callout->querySelectorAll('p'))->toHaveCount(1)
        ->and($callout->querySelector('p')?->getAttribute('class'))->toBe('kokpit-callout__label')
        ->and($callout->textContent)->not->toContain('[!NOTE]');
});

it('renders consecutive callouts and plain quotes independently', function (): void {
    $dom = calloutDom("> [!NOTE]\n> First.\n\nBetween.\n\n> [!WARNING]\n> Second.\n\n> Plain quote.");

    expect($dom->querySelectorAll('div.kokpit-callout'))->toHaveCount(2)
        ->and($dom->querySelectorAll('.kokpit-callout--note'))->toHaveCount(1)
        ->and($dom->querySelectorAll('.kokpit-callout--warning'))->toHaveCount(1)
        ->and($dom->querySelectorAll('blockquote'))->toHaveCount(1);
});

it('never takes the label from the body text', function (): void {
    $markup = '<'.'script>exampleMarker()</'.'script>';
    $dom = calloutDom("> [!NOTE]\n> {$markup}");

    expect($dom->querySelectorAll('script'))->toHaveCount(0)
        ->and($dom->querySelector('.kokpit-callout__label')?->textContent)->toBe('callouts.note');
});
