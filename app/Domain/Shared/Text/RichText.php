<?php

declare(strict_types=1);

namespace App\Domain\Shared\Text;

use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The one sanitiser for user-written rich text (task descriptions, later
 * comments), applied on write and again on render, whoever wrote the text (D-10).
 *
 * It owns a strict sanitiser of its own and deliberately does not touch
 * Filament's globally bound HtmlSanitizerConfig, which allows inline `style`,
 * `class` and `data-*` for the panel's own screens. Here only the safe elements
 * survive, with no attribute of the author's choosing: no style, class or event
 * handler, no script, frame, svg or image (`img` is dropped, so even a forged
 * editor value stores no picture), and links keep their `href` only for https,
 * http and mailto. Relative links are dropped, and every link opens with
 * `rel="noopener noreferrer nofollow"` and `target="_blank"`.
 *
 * The limit is measured in bytes, the unit the sanitiser itself counts in: the
 * sanitiser cuts longer input silently, so `clean()` refuses it instead and the
 * Actions turn the refusal into a field error. `render()` never refuses; text
 * that bypassed the Actions is cut and cleaned.
 */
final class RichText
{
    public const int MAX_LENGTH = 100000;

    private static ?HtmlSanitizer $sanitiser = null;

    /**
     * Cleans the HTML for storage. Null and content with no text left after
     * cleaning (an empty editor writes `<p></p>`) give null.
     *
     * @throws InvalidArgumentException when the input is longer than MAX_LENGTH bytes
     */
    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (strlen($html) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('The rich text is longer than '.self::MAX_LENGTH.' characters.');
        }

        $clean = self::sanitiser()->sanitize($html);

        return self::hasText($clean) ? $clean : null;
    }

    /**
     * Cleans the stored HTML again for output. Safe to print unescaped.
     */
    public static function render(?string $html): HtmlString
    {
        if ($html === null || $html === '') {
            return new HtmlString('');
        }

        return new HtmlString(self::sanitiser()->sanitize($html));
    }

    private static function sanitiser(): HtmlSanitizer
    {
        return self::$sanitiser ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->dropElement('img')
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowRelativeLinks(false)
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                ->withMaxInputLength(self::MAX_LENGTH),
        );
    }

    /**
     * Whether cleaned HTML holds any text besides tags and whitespace.
     */
    private static function hasText(string $clean): bool
    {
        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}]+/u', '', $text) !== '';
    }
}
