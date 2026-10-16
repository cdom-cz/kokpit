<?php

declare(strict_types=1);

namespace App\Domain\Documents\Support;

use Normalizer;

/**
 * Safe names for uploaded files (D-06). The file name a browser sends is untrusted text: it can carry
 * control characters, right-to-left overrides that disguise an extension, path separators and dots that
 * make a hidden file. The cleaned display name is what every screen shows and every download offers; the
 * stored object is named `<document uuid>.<extension>` and never contains any of this text.
 */
final class DocumentFileName
{
    /** The name used when nothing usable is left of the original. */
    public const string FALLBACK = 'soubor';

    /** The length of the `documents.name` column. */
    public const int MAX_LENGTH = 255;

    /**
     * The original name cleaned of control characters, bidirectional overrides, path separators and
     * leading dots, in Unicode NFC, cut to MAX_LENGTH characters; FALLBACK when nothing is left.
     */
    public static function displayName(string $original): string
    {
        $name = ltrim(self::clean($original), '. ');

        $name = trim(mb_substr($name, 0, self::MAX_LENGTH));

        return $name === '' ? self::FALLBACK : $name;
    }

    /**
     * The lower-case last dot segment of the cleaned name when it is a plain `[a-z0-9]{1,10}` token,
     * otherwise null (the stored object then has no extension part).
     */
    public static function extension(string $original): ?string
    {
        $name = ltrim(self::clean($original), '. ');

        if (! str_contains($name, '.')) {
            return null;
        }

        $extension = strtolower(substr($name, (int) strrpos($name, '.') + 1));

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : null;
    }

    /**
     * Whether any dot segment after the first one, in any letter case, is on the package's list of
     * disallowed extensions (`media-library.disallowed_extensions`), so `report.php.pdf` is caught as well
     * as `report.php`. A name that starts with a dot has an empty first segment, so `.htaccess` is caught.
     */
    public static function hasDisallowedExtension(string $original): bool
    {
        $segments = explode('.', mb_strtolower(self::clean($original)));

        array_shift($segments);

        /** @var list<string> $disallowed */
        $disallowed = array_map(
            static fn (string $extension): string => ltrim(mb_strtolower(trim($extension)), '.'),
            array_values((array) config('media-library.disallowed_extensions')),
        );

        foreach ($segments as $segment) {
            if (in_array(trim($segment), $disallowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * NFC, no control characters (C0, C1, DEL), no bidirectional embeddings, overrides, isolates or
     * marks, and no path separators; surrounding whitespace trimmed. Leading dots are kept, because the
     * extension check needs them.
     */
    private static function clean(string $original): string
    {
        $name = Normalizer::normalize($original, Normalizer::FORM_C);

        if ($name === false) {
            // Not valid UTF-8: drop the bytes that are not.
            $name = (string) mb_convert_encoding($original, 'UTF-8', 'UTF-8');
            $name = Normalizer::normalize($name, Normalizer::FORM_C);
            $name = $name === false ? '' : $name;
        }

        $name = (string) preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name = str_replace(['/', '\\'], '-', $name);

        return trim($name);
    }
}
