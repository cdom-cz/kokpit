<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Mail;

use Illuminate\Support\HtmlString;

/**
 * A plain-text body as escaped HTML paragraph blocks, for a Markdown mail slot (R-13-05-3, D-01, T-13-12).
 *
 * The text an Admin types is free text, not Markdown. Printed into the Markdown slot of a mail view it would be parsed:
 * a dash list, a heading, emphasis or a link would turn into markup. This class escapes every line with `e()` and wraps
 * each paragraph in a `<p>` that starts flush left, which CommonMark reads as an HTML block that ends at the next blank
 * line, so nothing inside it is parsed as Markdown. Line breaks and blank lines of the text are kept (`<br>` and a new
 * paragraph). No sanitiser and no rich-text editor are involved: everything is escaped, nothing is allowed through.
 */
final class PlainTextHtml
{
    public static function paragraphs(string $text): HtmlString
    {
        $normalised = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($normalised === '') {
            return new HtmlString('');
        }

        $blocks = [];

        foreach (preg_split('/\n{2,}/', $normalised) ?: [] as $paragraph) {
            $lines = array_map(static fn (string $line): string => e($line), explode("\n", $paragraph));

            $blocks[] = '<p>'.implode("<br>\n", $lines).'</p>';
        }

        return new HtmlString(implode("\n\n", $blocks));
    }
}
