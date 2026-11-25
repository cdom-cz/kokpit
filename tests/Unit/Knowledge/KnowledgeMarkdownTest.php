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

it('renders a GFM table with header and body cells', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml("| Example head | Other head |\n| --- | --- |\n| Example cell | Other cell |\n"));

    expect($dom->querySelectorAll('table'))->toHaveCount(1)
        ->and($dom->querySelectorAll('th'))->toHaveCount(2)
        ->and($dom->querySelectorAll('td'))->toHaveCount(2)
        ->and($dom->querySelector('td')?->textContent)->toBe('Example cell');
});

it('renders a task item as a disabled aria-hidden checkbox followed by a hidden done or open prefix', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml("- [x] Example done\n- [ ] Example open\n"));
    $boxes = $dom->querySelectorAll('input[type="checkbox"]');

    expect($boxes)->toHaveCount(2);

    $done = $boxes->item(0);
    $open = $boxes->item(1);

    expect($done->hasAttribute('checked'))->toBeTrue()
        ->and($open->hasAttribute('checked'))->toBeFalse();

    foreach ([[$done, 'task.done', 'Example done'], [$open, 'task.open', 'Example open']] as [$box, $prefix, $text]) {
        $label = $box->nextElementSibling;

        expect($box->hasAttribute('disabled'))->toBeTrue()
            ->and($box->getAttribute('aria-hidden'))->toBe('true')
            ->and($label?->getAttribute('class'))->toBe('kokpit-sr-only')
            ->and(trim((string) $label?->textContent))->toBe($prefix)
            ->and(preg_replace('/\s+/', ' ', trim((string) $box->parentNode?->textContent)))->toBe($prefix.' '.$text);
    }
});

it('renders strikethrough and autolinks of https urls', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml('~~Example gone~~ and https://example.com/auto here'));

    expect($dom->querySelector('del')?->textContent)->toBe('Example gone')
        ->and($dom->querySelector('a')?->getAttribute('href'))->toBe('https://example.com/auto');
});

it('does not truncate a long paragraph at the default sanitiser limit', function (): void {
    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml(KnowledgeHostileCorpus::longParagraph(30000)));

    expect(mb_strlen($dom->body->textContent))->toBeGreaterThanOrEqual(30000);
});

it('renders an adversarial flood without error within the memory and time budget', function (string $method): void {
    $markdown = KnowledgeHostileCorpus::{$method}(100000);
    $renderer = knowledgeMarkdownRenderer();

    $before = memory_get_usage();
    memory_reset_peak_usage();
    $started = microtime(true);
    $html = (string) $renderer->render($markdown);
    $seconds = microtime(true) - $started;
    $extra = memory_get_peak_usage() - $before;

    expect($html)->not->toBe('')
        ->and($extra)->toBeLessThanOrEqual(256 * 1024 * 1024)
        ->and($seconds)->toBeLessThan(5.0);
})->with(['bracketFlood', 'emphasisFlood', 'backtickFlood']);

it('renders a body above the size cap as escaped plain text without markdown processing', function (): void {
    $body = '**Example** '.str_repeat('a', KnowledgeMarkdown::MAX_INPUT_LENGTH - 11).' <b>x</b>';

    expect(mb_strlen($body))->toBeGreaterThan(KnowledgeMarkdown::MAX_INPUT_LENGTH);

    $dom = knowledgeMarkdownDom(knowledgeMarkdownHtml($body));
    $plain = $dom->querySelector('p.kokpit-prose__plain');

    expect($plain)->not->toBeNull()
        ->and($plain->textContent)->toBe($body)
        ->and($dom->querySelectorAll('strong, b'))->toHaveCount(0);
});

it('renders a body of MAX_INPUT_LENGTH + 1 characters as plain text and of exactly the cap as markdown', function (): void {
    $over = '**Example** '.str_repeat('a', KnowledgeMarkdown::MAX_INPUT_LENGTH + 1 - 12);
    $exact = '**Example** '.str_repeat('a', KnowledgeMarkdown::MAX_INPUT_LENGTH - 12);

    expect(mb_strlen($over))->toBe(KnowledgeMarkdown::MAX_INPUT_LENGTH + 1)
        ->and(mb_strlen($exact))->toBe(KnowledgeMarkdown::MAX_INPUT_LENGTH)
        ->and(knowledgeMarkdownDom(knowledgeMarkdownHtml($over))->querySelectorAll('strong'))->toHaveCount(0)
        ->and(knowledgeMarkdownHtml($over))->toContain('**Example**')
        ->and(knowledgeMarkdownDom(knowledgeMarkdownHtml($exact))->querySelectorAll('strong'))->toHaveCount(1);
});
