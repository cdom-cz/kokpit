<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Puts an article body into its canonical stored form (D-10), so a save without real changes is
 * recognised as one (VER-01).
 *
 * Only prose lines are normalised: a leading BOM is removed, trailing spaces and tabs are trimmed, line
 * endings become LF and trailing blank prose lines are removed. Every byte of every fenced and indented
 * code block stays as typed, its line endings and trailing whitespace included: which lines are code is
 * decided by the CommonMark parser (the same parser, extensions and nesting limit as the renderer), never
 * by a pattern over backticks, so fences in quotes and list items, tilde fences, indented code and an
 * unclosed fence are all recognised. A Markdown hard line break made of two trailing spaces on a prose
 * line is therefore removed.
 *
 * The text must be valid UTF-8; the parser throws a CommonMark exception otherwise.
 */
final class MarkdownNormaliser
{
    private readonly MarkdownParser $parser;

    public function __construct()
    {
        $environment = new Environment(['max_nesting_level' => 20]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $this->parser = new MarkdownParser($environment);
    }

    public function normalise(string $markdown): string
    {
        if (str_starts_with($markdown, "\u{FEFF}")) {
            $markdown = substr($markdown, 3);
        }

        if ($markdown === '') {
            return '';
        }

        $lines = $this->lines($markdown);
        $code = $this->codeLines($markdown);

        /** @var list<array{string, string, bool}> $out [text, line ending, is code] */
        $out = [];

        foreach ($lines as $index => [$text, $ending]) {
            $out[] = isset($code[$index + 1])
                ? [$text, $ending, true]
                : [rtrim($text, " \t"), $ending === '' ? '' : "\n", false];
        }

        while ($out !== [] && ! $out[array_key_last($out)][2] && $out[array_key_last($out)][0] === '') {
            array_pop($out);
        }

        if ($out !== [] && ! $out[array_key_last($out)][2]) {
            $out[array_key_last($out)][1] = '';
        }

        return implode('', array_map(static fn (array $line): string => $line[0].$line[1], $out));
    }

    /**
     * The lines of the text, each with the line ending that terminates it (empty for the last line of a
     * text that does not end in one). The separators and the rule that a final line ending opens no empty
     * last line are those of the parser's own input, so line k here is line k of the parser.
     *
     * @return list<array{string, string}>
     */
    private function lines(string $markdown): array
    {
        $parts = preg_split('/(\r\n|\r|\n)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            throw new \RuntimeException('The text could not be split into lines.');
        }

        $lines = [];

        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            $lines[] = [$parts[$i], $parts[$i + 1] ?? ''];
        }

        if (count($lines) > 1 && end($lines) === ['', '']) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * The 1-based numbers of the lines that belong to a fenced or an indented code block, fences included.
     *
     * @return array<int, true>
     */
    private function codeLines(string $markdown): array
    {
        $code = [];

        foreach ($this->parser->parse($markdown)->iterator() as $node) {
            if ($node instanceof FencedCode || $node instanceof IndentedCode) {
                for ($line = (int) $node->getStartLine(); $line <= (int) $node->getEndLine(); $line++) {
                    $code[$line] = true;
                }
            }
        }

        return $code;
    }
}
