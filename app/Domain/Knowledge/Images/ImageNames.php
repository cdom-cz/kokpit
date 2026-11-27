<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

use Normalizer;

/**
 * The two texts made of the file name a client sent (D-03). The name is attacker-controlled text: it never decides
 * anything about the file (D-07) and it never reaches the object key (that is built from the id alone), but it is
 * shown in the Obrázky table and prefilled as the alt text of the Markdown reference that the editor inserts. The alt
 * text therefore has to be inert inside `![alt](url)`: no bracket, parenthesis or backslash, no control, format or
 * bidi character, and a bounded length (research Pitfall 10).
 */
final class ImageNames
{
    public const int MAX_ALT_LENGTH = 120;

    public const int MAX_FILE_NAME_LENGTH = 255;

    /**
     * The default alt text: the base name without its last extension, made inert for Markdown. It may be empty, and an
     * empty alt text is allowed (D-03).
     */
    public static function altText(string $clientName): string
    {
        $text = self::baseName($clientName);

        $dot = strrpos($text, '.');

        if ($dot !== false) {
            $text = substr($text, 0, $dot);
        }

        // Whitespace first (a tab or a line break becomes a space), then the characters that are never wanted.
        $text = self::replace('/\s+/u', ' ', $text);
        $text = self::replace('/[\p{Cc}\p{Cf}]/u', '', $text);
        $text = str_replace(['[', ']', '(', ')', '\\'], '', $text);
        $text = self::replace('/\s+/u', ' ', $text);
        $text = self::normalise(trim($text));

        return trim(mb_substr($text, 0, self::MAX_ALT_LENGTH));
    }

    /**
     * The file name stored with the image row: the base name without control, format and bidi characters, at most 255
     * characters; an empty result becomes `image.` plus the extension of the format.
     */
    public static function fileName(string $clientName, ImageFormat $format): string
    {
        $text = self::baseName($clientName);
        $text = self::replace('/[\p{Cc}\p{Cf}]/u', '', $text);
        $text = trim(self::normalise($text));
        $text = trim(mb_substr($text, 0, self::MAX_FILE_NAME_LENGTH));

        return $text === '' ? 'image.'.$format->extension() : $text;
    }

    /**
     * The part after the last slash, as valid UTF-8 (an invalid sequence is replaced by a question mark). A backslash is
     * an ordinary character here and is removed from the alt text with the other Markdown breakers.
     */
    private static function baseName(string $clientName): string
    {
        $name = mb_scrub($clientName);

        $slash = strrpos($name, '/');

        return $slash === false ? $name : substr($name, $slash + 1);
    }

    private static function normalise(string $text): string
    {
        if (! class_exists(Normalizer::class)) {
            return $text;
        }

        $normalised = Normalizer::normalize($text, Normalizer::FORM_C);

        return $normalised === false ? $text : $normalised;
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? '';
    }
}
