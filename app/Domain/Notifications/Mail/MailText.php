<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Mail;

/**
 * The one Markdown escape of the e-mail text and its reversal for the plain-text part (R-13-04-2, P13-08).
 *
 * A value that comes from outside (a task title, a client name) is printed on a Markdown line of a notification. The
 * HTML part is built from that Markdown, so the value is made inert first by `escapeMarkdown()`. The plain-text part is
 * not parsed as Markdown: it is the same view rendered with the text components, so the escape backslashes would show.
 * The published text layout therefore runs everything through `unescapeMarkdown()`, which removes exactly the
 * backslashes `escapeMarkdown()` put in (research Pitfall 5). Every notification that escapes does it here, never with
 * a private copy.
 */
final class MailText
{
    /**
     * Makes a plain-text value inert in the Markdown of the mail: no link, image,
     * emphasis, code, heading or table can be built from it, and a line break can
     * not start a new block. White space collapses to one space, except the no-break spaces of a formatted amount.
     *
     * `<` and `>` are left alone on purpose: the mail renderer HTML-encodes every
     * line before it parses the Markdown, so they can not open a tag, and a
     * backslash in front of them would make the renderer show a literal "&lt;".
     * Do not apply e() to a mail line either: it would be encoded twice and read
     * "&amp;".
     */
    public static function escapeMarkdown(string $text): string
    {
        // A no-break space (U+00A0) and a narrow no-break space (U+202F) stay: they hold a formatted amount together.
        $flat = (string) preg_replace('/[^\S\x{00A0}\x{202F}]+/u', ' ', $text);

        return (string) preg_replace('/([\\\\`*_\[\]()#|~!])/', '\\\\$1', $flat);
    }

    /**
     * Removes exactly one backslash in front of each character `escapeMarkdown()` escapes, left to right, so the
     * plain-text part reads as the value was written.
     */
    public static function unescapeMarkdown(string $text): string
    {
        return (string) preg_replace('/\\\\([\\\\`*_\[\]()#|~!])/', '$1', $text);
    }
}
