<?php

declare(strict_types=1);

namespace App\Domain\Shared\Text;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Turns the stored rich text into Markdown for the clipboard (the task assignment copy).
 *
 * The HTML is cleaned again through {@see RichText::render()} before the converter sees it, so
 * text written around the Actions is as harmless here as on every page. ATX headings keep the
 * editor's h2 and h3 as `##` and `###` under the one H1 the title gets. Tags with no Markdown
 * form (underline) collapse to their text. The editor offers tables, which the library leaves out
 * by default, so the table converter is added; strike has no default converter either, so
 * {@see StrikethroughConverter} writes it as `~~`. The result is plain text that no caller prints
 * as HTML.
 */
final class RichTextMarkdown
{
    private static ?HtmlConverter $converter = null;

    /**
     * The stored HTML as Markdown: no trailing newline, no whitespace-only line, no run of blank
     * lines. Null, an empty string and content with no text give an empty string.
     */
    public static function convert(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $markdown = self::converter()->convert(RichText::render($html)->toHtml());

        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = (string) preg_replace('/^[ \t]+$/m', '', $markdown);
        $markdown = (string) preg_replace('/\n{3,}/', "\n\n", $markdown);

        return trim($markdown);
    }

    /**
     * The whole assignment: the title as the one H1 (whitespace collapsed so it stays on one line,
     * Markdown characters not escaped), a blank line, the converted description and one newline.
     * Without a description only the heading line is returned.
     */
    public static function document(string $title, ?string $html): string
    {
        $heading = '# '.trim((string) preg_replace('/\s+/u', ' ', $title));
        $body = self::convert($html);

        if ($body === '') {
            return $heading."\n";
        }

        return $heading."\n\n".$body."\n";
    }

    private static function converter(): HtmlConverter
    {
        if (self::$converter === null) {
            $converter = new HtmlConverter([
                'header_style' => 'atx',
                'strip_tags' => true,
            ]);

            $converter->getEnvironment()->addConverter(new TableConverter);
            $converter->getEnvironment()->addConverter(new StrikethroughConverter);

            self::$converter = $converter;
        }

        return self::$converter;
    }
}
