<?php

declare(strict_types=1);

use App\Domain\Shared\Text\RichTextMarkdown;

/*
 * This file pins the HTML-to-Markdown contract of the task assignment copy: every format the
 * description editor offers, the cleaning of hostile markup, empty input, title whitespace,
 * diacritics and newline normalisation. Unit tests run without the application, so the class
 * is called statically. Every text is fictional; the hostile parts are assembled from fragments
 * at runtime, so no single line of this file looks like a payload.
 */

/**
 * A description that uses every kind of formatting the editor offers, in the shapes tiptap writes
 * (paragraphs inside list items, table cells and the quote).
 */
function richMdFormatted(): string
{
    return '<h2>Example heading</h2>'
        .'<h3>Example subheading</h3>'
        .'<p><strong>Example bold</strong> <em>Example italic</em> <u>Example underline</u> <s>Example strike</s> '
        .'<a href="https://example.com/page">Example link</a></p>'
        .'<ul><li><p>Example bullet</p></li></ul>'
        .'<ol><li><p>Example first</p></li><li><p>Example second</p></li></ol>'
        .'<blockquote><p>Example quote</p></blockquote>'
        .'<table><tbody><tr><th><p>Example head</p></th><td><p>Example cell</p></td></tr></tbody></table>';
}

/**
 * A hostile description assembled from fragments at runtime.
 */
function richMdForged(): string
{
    $lt = '<';

    return implode('', [
        $lt.'scr'.'ipt>window.markerOne()'.$lt.'/scr'.'ipt>',
        $lt.'img src="https://example.com/marker'.'Two.png" on'.'error="markerThree()">',
        $lt.'a href="java'.'script:markerFive()">Example link text'.$lt.'/a>',
        $lt.'p style="position:'.'fixed;top:markerFour">Benign words'.$lt.'/p>',
    ]);
}

it('converts every format the editor offers', function (): void {
    $markdown = RichTextMarkdown::convert(richMdFormatted());

    expect($markdown)
        ->toMatch('/^## Example heading$/m')
        ->toMatch('/^### Example subheading$/m')
        ->toContain('**Example bold**')
        ->toContain('*Example italic*')
        ->toContain('~~Example strike~~')
        ->toContain('[Example link](https://example.com/page)')
        ->toContain('Example underline')
        ->not->toContain('<u>')
        ->not->toContain('</u>')
        ->toMatch('/^- Example bullet$/m')
        ->toMatch('/^1\. Example first$/m')
        ->toMatch('/^2\. Example second$/m')
        ->toMatch('/^> Example quote$/m')
        ->toMatch('/^\| Example head \| Example cell \|$/m')
        ->toMatch('/^\|\s*:?-+:?\s*\|\s*:?-+:?\s*\|$/m');
});

it('keeps the nesting of a list', function (): void {
    $html = '<ul><li><p>Example parent</p><ul><li><p>Example child</p></li></ul></li></ul>';

    $markdown = RichTextMarkdown::convert($html);

    expect($markdown)
        ->toMatch('/^- Example parent$/m')
        ->toMatch('/^ +- Example child$/m');
});

it('puts the two sides of a line break on different lines', function (): void {
    $markdown = RichTextMarkdown::convert('<p>Example line one<br>Example line two</p>');

    expect($markdown)->toContain('Example line one')->toContain('Example line two');

    $lines = array_values(array_filter(array_map('trim', explode("\n", $markdown))));

    expect($lines)->toBe(['Example line one', 'Example line two']);
});

it('cleans hostile markup before it converts', function (): void {
    $markdown = RichTextMarkdown::convert(richMdForged());

    foreach (['markerOne', 'markerTwo', 'markerThree', 'markerFour', 'markerFive'] as $marker) {
        expect($markdown)->not->toContain($marker);
    }

    expect($markdown)
        ->toContain('Benign words')
        ->toContain('Example link text')
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('style=')
        ->not->toContain('javascript:');
});

it('gives an empty string for no content', function (): void {
    expect(RichTextMarkdown::convert(null))->toBe('')
        ->and(RichTextMarkdown::convert(''))->toBe('')
        ->and(RichTextMarkdown::convert('<p></p>'))->toBe('');
});

it('gives only the heading for a document without a description', function (): void {
    expect(RichTextMarkdown::document('Example title', null))->toBe("# Example title\n")
        ->and(RichTextMarkdown::document('Example title', '<p></p>'))->toBe("# Example title\n");
});

it('puts the title, one blank line and the body in a document', function (): void {
    expect(RichTextMarkdown::document('Example title', '<p>Example body</p>'))
        ->toBe("# Example title\n\nExample body\n");
});

it('collapses the whitespace of the title into single spaces', function (): void {
    $title = '  Example'."\n".'  spaced'."\t".'title  ';

    expect(RichTextMarkdown::document($title, null))->toBe("# Example spaced title\n");
});

it('keeps Czech diacritics intact', function (): void {
    expect(RichTextMarkdown::convert('<p>Příklad úkolu s háčky</p>'))->toContain('Příklad úkolu s háčky');
});

it('writes no whitespace-only line, no run of blank lines and no stray edge whitespace', function (): void {
    $markdown = RichTextMarkdown::convert(richMdFormatted());

    expect($markdown)
        ->not->toMatch('/^[ \t]+$/m')
        ->not->toContain("\n\n\n")
        ->not->toMatch('/^\s/')
        ->not->toMatch('/\s$/');
});
