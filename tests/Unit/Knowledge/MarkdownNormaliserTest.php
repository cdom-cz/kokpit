<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\MarkdownNormaliser;

/*
 * The normaliser puts a body into its canonical stored form (D-10): prose lines lose trailing spaces and
 * tabs and get LF endings, trailing blank prose lines go, and every byte of fenced and indented code
 * stays as typed. Unit tests run without the application. Fixtures that hold CR, CRLF, tabs or trailing
 * spaces are double-quoted strings with escapes, never raw bytes: .gitattributes and .editorconfig
 * would rewrite them. Every text is fictional.
 */

function normaliserRun(string $text): string
{
    return (new MarkdownNormaliser)->normalise($text);
}

/**
 * Code blocks written with CRLF endings, a lone CR, trailing spaces and tabs and an inner blank line,
 * each with the text that precedes it and the bytes of the block itself (first character of the first
 * line through the ending of the last line).
 *
 * @return array<string, array{string, string}> [text, block]
 */
function normaliserCodeFixtures(): array
{
    $fence = "```php\r\n\$total = 1;  \t\r\n\r\nlast line \r\n```\r\n";
    $tilde = "~~~\r\nfirst\rsecond  \r\n\t\r\n~~~\r\n";
    $quoted = "> ```\r\n> code  \r\n> \r\n> ```\r\n";
    $listed = "  ```\r\n  code \t\r\n\r\n  more  \r\n  ```\r\n";
    $indented = "    indented  \t\r\n\r\n      more\r\n";

    return [
        'backtick fence' => ["Intro  \r\n\r\n".$fence."\r\nAfter  \r\n", $fence],
        'tilde fence with a lone CR' => ["Intro  \r\n\r\n".$tilde."\r\nAfter  \r\n", $tilde],
        'fence in a block quote' => ["Intro  \r\n\r\n".$quoted."\r\nAfter  \r\n", $quoted],
        'fence in a list item' => ["- item  \r\n\r\n".$listed."\r\nAfter  \r\n", $listed],
        'indented block' => ["Intro  \r\n\r\n".$indented."\r\nAfter  \r\n", $indented],
    ];
}

it('converts the line endings of prose to LF and removes a leading BOM', function (): void {
    expect(normaliserRun("a\r\nb\rc"))->toBe("a\nb\nc")
        ->and(normaliserRun("\u{FEFF}Title\n\nText"))->toBe("Title\n\nText")
        ->and(normaliserRun("One\r\n\r\nTwo\r\n"))->toBe("One\n\nTwo");
});

it('trims trailing spaces and tabs of prose lines and removes trailing blank lines', function (): void {
    expect(normaliserRun("One  \nTwo\t\n   \nThree \t \n\n\n  \n"))->toBe("One\nTwo\n\nThree")
        ->and(normaliserRun("\n\n"))->toBe('')
        ->and(normaliserRun("   \n"))->toBe('')
        ->and(normaliserRun(''))->toBe('');
});

it('removes the two trailing spaces of a hard line break on a prose line', function (): void {
    expect(normaliserRun("line one  \nline two"))->toBe("line one\nline two");
});

it('keeps every byte of every kind of code block', function (string $name): void {
    [$text, $block] = normaliserCodeFixtures()[$name];

    expect(normaliserRun($text))->toContain($block);
})->with([
    'backtick fence',
    'tilde fence with a lone CR',
    'fence in a block quote',
    'fence in a list item',
    'indented block',
]);

it('pins the whole output around a top-level fence', function (): void {
    $fence = "```\r\ncode  \r\n\r\n\tmore\r\n```";

    expect(normaliserRun("Intro  \r\n".$fence."\r\nAfter  \r\n\r\n"))->toBe("Intro\n".$fence."\r\nAfter");
});

it('treats a CR inside code as content, never unifying it', function (): void {
    $crlf = "```\r\ncode\r\n```";
    $lf = "```\ncode\n```";

    expect(normaliserRun($crlf))->toBe($crlf)
        ->and(normaliserRun($lf))->toBe($lf)
        ->and(normaliserRun($crlf))->not->toBe(normaliserRun($lf));
});

it('keeps an unclosed fence byte for byte through the end of the text', function (): void {
    expect(normaliserRun("intro\r\n~~~\r\nline  \r\n\r\n   \r\n"))->toBe("intro\n~~~\r\nline  \r\n\r\n   \r\n")
        ->and(normaliserRun("intro\r\n```\r\ncode"))->toBe("intro\n```\r\ncode")
        ->and(normaliserRun("intro\n```\ncode\n\n\n"))->toBe("intro\n```\ncode\n\n\n");
});

it('keeps the ending of a final code line and drops the blank lines after it', function (): void {
    expect(normaliserRun("```\ncode\n```\n\n\n\n"))->toBe("```\ncode\n```\n")
        ->and(normaliserRun("    a\n      \n"))->toBe("    a\n")
        ->and(normaliserRun("```\ncode\n```"))->toBe("```\ncode\n```");
});

it('is idempotent on every code fixture', function (): void {
    foreach (normaliserCodeFixtures() as [$text]) {
        $once = normaliserRun($text);

        expect(normaliserRun($once))->toBe($once);
    }

    foreach (["intro\r\n~~~\r\nline  \r\n\r\n   \r\n", "\u{FEFF}A \r\n\r\n\r\n", "    a\n      \n", "x  \r\n```\r\ncode  "] as $text) {
        $once = normaliserRun($text);

        expect(normaliserRun($once))->toBe($once);
    }
});

/**
 * One generated body: its text and the bytes of every code block in it.
 *
 * @return array{string, list<string>}
 */
function normaliserGenerate(): array
{
    $words = ['alpha', 'beta', 'gamma', 'delta', 'omega', 'setup', 'notes', 'step'];
    $space = ['', ' ', "\t", '  ', " \t"];
    $pick = static fn (array $items): string => $items[mt_rand(0, count($items) - 1)];

    /** @var list<array{string, string, int|null}> $lines [text, ending, block id] */
    $lines = [];
    $block = 0;
    $add = static function (string $text, ?int $id = null) use (&$lines): void {
        $lines[] = [$text, "\n", $id];
    };

    $prose = static fn (): string => $pick($words).' '.$pick($words).$pick($space);

    for ($part = mt_rand(2, 5); $part > 0; $part--) {
        $kind = mt_rand(0, 4);
        $block++;

        match ($kind) {
            0 => $add($prose()),
            1 => (static function () use ($add, $pick, $space, $block): void {
                $fence = $pick(['```', '~~~']);
                $add($fence.$pick(['', 'php', ' text']).$pick($space), $block);
                foreach (range(1, mt_rand(1, 3)) as $i) {
                    $add($i === 2 && mt_rand(0, 1) === 1 ? '' : 'code '.$i.$pick($space), $block);
                }
                $add($fence.$pick($space), $block);
            })(),
            2 => (static function () use ($add, $pick, $space, $block): void {
                $add('> ```', $block);
                $add('> code '.$pick(['one', 'two']).$pick($space), $block);
                $add($pick(['>', '> ']), $block);
                $add('> ```', $block);
            })(),
            3 => (static function () use ($add, $pick, $space, $block): void {
                $add('- item');
                $add('');
                $add('  ```', $block);
                $add('  code '.$pick(['one', 'two']).$pick($space), $block);
                $add('  ```', $block);
            })(),
            default => (static function () use ($add, $pick, $space, $block): void {
                $add('paragraph before');
                $add('');
                $add('    code '.$pick(['one', 'two']).$pick($space), $block);
                $add($pick(['', '      ']), $block);
                $add('    more '.$pick(['one', 'two']).$pick($space), $block);
            })(),
        };

        $add('');
    }

    if (mt_rand(0, 1) === 1) {
        $block++;
        $add('~~~', $block);
        $add('open '.$pick($space), $block);
        $add('', $block);
        $add('  ', $block);
    }

    foreach ($lines as $i => $line) {
        $lines[$i][1] = $pick(["\n", "\r", "\r\n"]);
    }

    // A lone CR followed by an empty line that ends in LF would merge into one CRLF and change the line structure.
    foreach ($lines as $i => $line) {
        $next = $lines[$i + 1] ?? null;

        if ($line[1] === "\r" && $next !== null && $next[0] === '' && str_starts_with($next[1], "\n")) {
            $lines[$i][1] = "\n";
        }
    }

    // The last line of a text may have no ending at all.
    $last = array_key_last($lines);

    if ($lines[$last][2] !== null && mt_rand(0, 1) === 1) {
        $lines[$last][1] = '';
    }

    $text = implode('', array_map(static fn (array $line): string => $line[0].$line[1], $lines));
    $blocks = [];

    foreach ($lines as [$lineText, $ending, $id]) {
        if ($id !== null) {
            $blocks[$id] = ($blocks[$id] ?? '').$lineText.$ending;
        }
    }

    return [$text, array_values($blocks)];
}

it('is idempotent and keeps every code block of 200 generated bodies verbatim', function (): void {
    mt_srand(18);

    for ($i = 0; $i < 200; $i++) {
        [$text, $blocks] = normaliserGenerate();
        $once = normaliserRun($text);

        expect(normaliserRun($once))->toBe($once);

        foreach ($blocks as $block) {
            expect($once)->toContain($block);
        }
    }
});
