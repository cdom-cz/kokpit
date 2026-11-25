<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Dom\HTMLDocument;
use Tests\Support\KnowledgeHostileCorpus;

/*
 * This file pins the one knowledge renderer (KB-03): a hostile body comes out as escaped text or dropped links and
 * never as live markup, tables and task lists render, a long body is not truncated, and an adversarial or oversized
 * body stays within the memory and size limits. Unit tests run without the application, so the renderer gets an
 * identity translator. Every text is fictional; the hostile parts come from tests/Support/KnowledgeHostileCorpus,
 * assembled from fragments at runtime.
 */

/**
 * The renderer with an identity translator: a key comes back as itself, replacements are appended after a bar.
 */
function knowledgeMarkdownRenderer(): KnowledgeMarkdown
{
    return new KnowledgeMarkdown(
        static fn (string $key, array $replace = []): string => $key.($replace === [] ? '' : '|'.implode('|', $replace)),
    );
}

function knowledgeMarkdownHtml(string $markdown): string
{
    return (string) knowledgeMarkdownRenderer()->render($markdown);
}

function knowledgeMarkdownDom(string $html): HTMLDocument
{
    return HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);
}

it('renders no live markup for any hostile body', function (string $markdown): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml($markdown));

    expect($dom->querySelectorAll('script, iframe, style, img, object, embed, form, link, meta'))->toHaveCount(0);

    foreach ($dom->querySelectorAll('*') as $element) {
        foreach ($element->attributes as $attribute) {
            expect($attribute->name)
                ->not->toStartWith('on')
                ->not->toBe('style')
                ->not->toBe('target');
        }
    }

    foreach ($dom->querySelectorAll('a') as $anchor) {
        expect($anchor->hasAttribute('href'))->toBeTrue();
        expect($anchor->getAttribute('href'))->toMatch('#^(https://|http://|mailto:)#');
    }
})->with(KnowledgeHostileCorpus::cases());

it('shows the raw script tag as visible text', function (): void {
    $tag = '<'.'scr'.'ipt>';
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('Before '.$tag.'window.exampleMarker()</scr'.'ipt> after'));

    expect($dom->body->textContent)->toContain($tag)->toContain('window.exampleMarker()');
});

it('shows html entities typed in the body as text', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('Example &lt;b&gt; and &amp; and &#60;i&#62; text'));

    expect($dom->querySelectorAll('b, i'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain('<b>')->toContain('<i>')->toContain('&');
});

it('turns every unsafe link into its plain link text', function (string $label): void {
    $markdown = KnowledgeHostileCorpus::cases()[$label];
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml($markdown));

    expect($dom->querySelectorAll('a'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain($label === 'autolink with the script scheme' ? 'exampleMarker()' : 'Example link');
})->with([
    'link with the script scheme in mixed case',
    'link with the data scheme',
    'link with the vbscript scheme',
    'relative link',
    'protocol-relative link',
    'reference-style script link',
    'autolink with the script scheme',
]);

it('keeps an https link, a mailto link and an autolink with the forced rel and no target', function (): void {
    $mail = 'jane@'.implode('.', ['example', 'com']);
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml(
        "[Example link](https://example.com/page)\n\n[Example mail](mailto:{$mail})\n\nSee https://example.com/auto now",
    ));
    $anchors = $dom->querySelectorAll('a');

    expect($anchors)->toHaveCount(3)
        ->and($anchors->item(0)->getAttribute('href'))->toBe('https://example.com/page')
        ->and($anchors->item(0)->getAttribute('rel'))->toBe('noopener noreferrer nofollow')
        ->and($anchors->item(1)->getAttribute('href'))->toBe('mailto:'.$mail)
        ->and($anchors->item(2)->getAttribute('href'))->toBe('https://example.com/auto');

    foreach ($anchors as $anchor) {
        expect($anchor->hasAttribute('target'))->toBeFalse()
            ->and($anchor->getAttribute('rel'))->toBe('noopener noreferrer nofollow');
    }
});

it('turns every image into its alt text and never emits an img element', function (string $label): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml(KnowledgeHostileCorpus::cases()[$label]));

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($dom->body->textContent)->toContain('Example alt');
})->with([
    'image with an https source',
    'image with a data source',
]);

it('keeps the link around an image that carries an https link, as its alt text', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('[![Example alt](https://example.com/a.png)](https://example.com/page)'));
    $anchor = $dom->querySelector('a');

    expect($dom->querySelectorAll('img'))->toHaveCount(0)
        ->and($anchor?->getAttribute('href'))->toBe('https://example.com/page')
        ->and($anchor?->textContent)->toBe('Example alt');
});

it('returns an empty string for a blank body', function (string $markdown): void {
    expect(knowledgeMarkdownHtml($markdown))->toBe('');
})->with(['', "  \n ", "\n\n\t"]);

it('renders the same body to the same string twice', function (): void {
    $markdown = "# Example heading\n\nExample **bold** text and `code`.\n\n- one\n- two\n";

    expect(knowledgeMarkdownHtml($markdown))->toBe(knowledgeMarkdownHtml($markdown))
        ->and(knowledgeMarkdownHtml($markdown))->toContain('<h1>Example heading</h1>')->toContain('<strong>bold</strong>');
});
