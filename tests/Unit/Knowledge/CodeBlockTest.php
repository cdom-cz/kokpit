<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\CodeBlockRenderer;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Dom\HTMLDocument;
use Tempest\Highlight\Escape;
use Tempest\Highlight\Highlighter;

/*
 * This file pins the code block contract of the knowledge renderer (KB-03, KB-05, D-14): every code block carries
 * the fixed chrome with exactly one Copy button, the text of its code element equals the author's block text
 * exactly (it is what Copy copies), highlighting is accepted only when it cannot change that text, and a language
 * outside the allowlist renders as plain escaped text. Unit tests run without the application, so the renderer
 * gets an identity translator. Every snippet is fictional.
 */

/**
 * The identity translator: a key comes back as itself, replacements are appended after a bar.
 */
function codeBlockRenderer(): KnowledgeMarkdown
{
    return new KnowledgeMarkdown(
        static fn (string $key, array $replace = []): string => $key.($replace === [] ? '' : '|'.implode('|', $replace)),
    );
}

function codeBlockDom(string $markdown): HTMLDocument
{
    $html = (string) codeBlockRenderer()->render($markdown);

    return HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);
}

/**
 * The text of the first code element: what the Copy button copies.
 */
function codeBlockText(HTMLDocument $dom): string
{
    $code = $dom->querySelector('pre code');
    expect($code)->not->toBeNull();

    return $code->textContent;
}

/**
 * One sample per allowlisted language: inner blank line, trailing spaces inside the block, quotes and ampersands.
 *
 * @return array<string, array{0: string, 1: string}> language word => [block text, expected label]
 */
function codeBlockSamples(): array
{
    return [
        'php' => ["<?php\n\n// Example comment\necho 'Example' . \"text\";  \n\n\$value = 1 < 2 && true;", 'php'],
        'bash' => ["# Example comment\necho \"Example\" && ls -la  \n\nexport EXAMPLE=1", 'bash'],
        'sql' => ["SELECT id, name\nFROM examples\nWHERE name = 'Example' AND id > 1;", 'sql'],
        'json' => ["{\n    \"example\": [1, 2, true],\n\n    \"text\": \"a < b & c\"\n}", 'json'],
        'yaml' => ["example:\n  - one\n  - two  \n\ntext: 'Example'", 'yaml'],
        'javascript' => ["// Example comment\nconst value = 1;\n\nconsole.log(\"Example\", value < 2);", 'javascript'],
    ];
}

it('keeps the exact text of a fenced block for every sample language and highlights it', function (string $language): void {
    [$text, $label] = codeBlockSamples()[$language];
    $dom = codeBlockDom("```{$language}\n{$text}\n```");

    expect(codeBlockText($dom))->toBe($text)
        ->and($dom->querySelector('.kokpit-code__lang')?->textContent)->toBe($label)
        ->and($dom->querySelector('pre')?->getAttribute('aria-label'))->toBe('code.label|'.$label);

    $classes = [];
    foreach ($dom->querySelectorAll('pre code span') as $span) {
        $classes[] = $span->getAttribute('class');
    }

    expect($classes)->not->toBeEmpty();
    foreach ($classes as $class) {
        expect($class)->toStartWith('hl-');
    }
})->with(['php', 'bash', 'sql', 'json', 'yaml', 'javascript']);

it('renders no hidden or styled markup around highlighted code', function (): void {
    foreach (codeBlockSamples() as $language => [$text]) {
        $html = (string) codeBlockRenderer()->render("```{$language}\n{$text}\n```");

        expect($html)->not->toContain('style=')->not->toContain('display: none');
    }
});

it('falls back to plain text when the code holds the highlighter markers', function (string $marker): void {
    $text = "<?php\n\$value = '{$marker}';\n\necho \$value;";
    $dom = codeBlockDom("```php\n{$text}\n```");

    expect(codeBlockText($dom))->toBe($text)
        ->and($dom->querySelectorAll('pre code span'))->toHaveCount(0)
        ->and($dom->querySelector('.kokpit-code__lang')?->textContent)->toBe('php');
})->with([
    'addition marker' => '{+ x +}',
    'deletion marker' => '{- x -}',
    'blur marker' => '{~ x ~}',
    'emphasis marker' => '{_ x _}',
]);

it('keeps the exact text of a diff block and highlights it with class-only spans', function (): void {
    $text = "--- a/example.txt\n+++ b/example.txt\n@@ -1,2 +1,2 @@\n context line\n-old line\n+new line";
    $dom = codeBlockDom("```diff\n{$text}\n```");

    expect(codeBlockText($dom))->toBe($text);

    foreach ($dom->querySelectorAll('pre code span') as $span) {
        expect($span->getAttribute('class'))->toStartWith('hl-');
    }
});

it('keeps the text when the code holds the highlighter escape tokens', function (): void {
    $text = "echo 'a".implode('', Escape::TOKEN_KEYS)."b';";
    $dom = codeBlockDom("```php\n{$text}\n```");

    expect(codeBlockText($dom))->toBe($text);
});

it('reads a CR or CRLF line ending kept inside stored code as one LF line break', function (): void {
    $dom = codeBlockDom("```bash\necho a\r\n\r\necho b  \recho c\r\n```");

    $text = codeBlockText($dom);
    expect($text)->toBe("echo a\n\necho b  \necho c")
        ->and($text)->not->toContain("\r");
});

it('renders an unknown language, an empty info string and an indented block as plain text without a label', function (string $markdown, string $text): void {
    $dom = codeBlockDom($markdown);

    expect(codeBlockText($dom))->toBe($text)
        ->and($dom->querySelectorAll('.kokpit-code__lang'))->toHaveCount(0)
        ->and($dom->querySelectorAll('pre code span'))->toHaveCount(0)
        ->and($dom->querySelector('pre')?->getAttribute('aria-label'))->toBe('code.label_plain');
})->with([
    'unknown language' => ["```brainfuck\n+++ [>+<-]\n```", '+++ [>+<-]'],
    'empty info string' => ["```\nexample line\n\nsecond line  \n```", "example line\n\nsecond line  "],
    'indented block' => ["Example paragraph.\n\n    echo 'Example';\n\n    echo \"Second\";", "echo 'Example';\n\necho \"Second\";"],
    'tilde fence' => ["~~~\nexample & <tag>\n~~~", 'example & <tag>'],
    'empty block' => ["```\n```", ''],
]);

it('renders an info string that carries markup as plain text with no label and no live markup', function (): void {
    $info = 'php"><'.'scr'.'ipt>exampleMarker()</scr'.'ipt>';
    $dom = codeBlockDom("```{$info}\nexample\n```");

    expect(codeBlockText($dom))->toBe('example')
        ->and($dom->querySelectorAll('.kokpit-code__lang'))->toHaveCount(0)
        ->and($dom->querySelectorAll('script'))->toHaveCount(0)
        ->and($dom->querySelector('pre')?->getAttribute('aria-label'))->toBe('code.label_plain');
});

it('resolves a language alias in any case to its label', function (string $word, string $label): void {
    $dom = codeBlockDom("```{$word}\nexample\n```");

    expect($dom->querySelector('.kokpit-code__lang')?->textContent)->toBe($label);
})->with([
    'sh' => ['sh', 'bash'],
    'JS' => ['JS', 'javascript'],
    'yml' => ['yml', 'yaml'],
    'Env' => ['Env', 'dotenv'],
    'info string with more words' => ['php title="Example"', 'php'],
]);

it('gives every code block exactly one Copy button with the translated labels and no code text inside', function (): void {
    $markup = '<'.'button data-kokpit-copy>Example</'.'button>';
    $dom = codeBlockDom(
        "```php\necho 'Example';\n```\n\n{$markup}\n\n```\nsecond block\n```\n\n    third block\n",
    );
    $buttons = $dom->querySelectorAll('[data-kokpit-copy]');

    expect($dom->querySelectorAll('.kokpit-code'))->toHaveCount(3)
        ->and($buttons)->toHaveCount(3);

    foreach ($buttons as $button) {
        expect($button->nodeName)->toBe('BUTTON')
            ->and($button->getAttribute('type'))->toBe('button')
            ->and($button->getAttribute('data-label-copied'))->toBe('code.copied')
            ->and($button->getAttribute('data-label-failed'))->toBe('code.copy_failed')
            ->and($button->querySelector('.kokpit-code__copy-label')?->textContent)->toBe('code.copy')
            ->and($button->querySelector('.kokpit-code__copy-label')?->getAttribute('aria-live'))->toBe('polite')
            ->and($button->textContent)->toBe('code.copy')
            ->and($button->parentNode?->getAttribute('class'))->toBe('kokpit-code__head');
    }

    foreach ($dom->querySelectorAll('pre') as $pre) {
        expect($pre->getAttribute('tabindex'))->toBe('0');
    }
});

it('maps every allowlisted language to a language Tempest resolves', function (): void {
    $supported = (new Highlighter)->getSupportedLanguageNames();

    expect(CodeBlockRenderer::LANGUAGES)->not->toBeEmpty();

    foreach (CodeBlockRenderer::LANGUAGES as $word => $name) {
        expect($word)->toBe(strtolower($word))
            ->and($supported)->toContain($name);
    }
});
