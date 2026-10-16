<?php

declare(strict_types=1);

namespace App\Domain\Documents\Support;

use Normalizer;

/**
 * Safe and unique entry names for the ZIP archive of documents (DO-02, research Pitfall 12, T-09-61).
 *
 * A document name is text a user chose, and an entry name becomes a path when the archive is unpacked. safe()
 * turns a name into one that cannot leave the folder it is unpacked into: no path separator, no parent-directory
 * segment, no leading dot, no control or bidirectional character, in Unicode NFC, with the stem cut to a fixed
 * number of characters and the extension kept. An instance of this class then makes the names of one archive
 * distinct, case-insensitively, because most file systems cannot hold `Smlouva.pdf` and `smlouva.pdf` side by
 * side: the later ones become `smlouva (2).pdf`, `Smlouva (3).pdf`.
 */
final class ArchiveEntryNames
{
    /** The name used when nothing usable is left of the original. */
    public const string FALLBACK = DocumentFileName::FALLBACK;

    /** The longest stem in characters, extension not counted. */
    public const int MAX_STEM_LENGTH = 150;

    /**
     * Whether a name was already handed out, by its lower-cased form.
     *
     * @var array<string, true>
     */
    private array $taken = [];

    /**
     * How many names of one lower-cased form were handed out, the last number used.
     *
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * The name cleaned for use as a ZIP entry. `$extension` is the extension of the document (plain
     * `[A-Za-z0-9]{1,10}`, else it is ignored); when none is given, a plain extension at the end of the name is
     * used. An extension the name already carries is not doubled.
     */
    public static function safe(string $name, ?string $extension): string
    {
        $clean = self::clean($name);
        $extension = self::plainExtension($extension);

        if ($extension !== null) {
            $clean = (string) preg_replace('/\.'.preg_quote($extension, '/').'$/i', '', $clean);
        } elseif (preg_match('/^(.+)\.([A-Za-z0-9]{1,10})$/u', $clean, $matches) === 1) {
            [, $clean, $extension] = $matches;
            $extension = strtolower($extension);
        }

        $stem = trim(mb_substr(ltrim($clean, '. '), 0, self::MAX_STEM_LENGTH), '. ');

        if ($stem === '') {
            $stem = self::FALLBACK;
        }

        return $extension === null ? $stem : $stem.'.'.$extension;
    }

    /**
     * The name made distinct from every name this instance handed out before, case-insensitively: the first
     * keeps its name, the later ones get ` (2)`, ` (3)` before the extension. A number is skipped when a name
     * with it is already taken.
     */
    public function unique(string $name): string
    {
        $key = mb_strtolower($name);

        if (! isset($this->taken[$key])) {
            $this->taken[$key] = true;
            $this->counts[$key] = 1;

            return $name;
        }

        $stem = $name;
        $suffix = '';

        if (preg_match('/^(.+)(\.[A-Za-z0-9]{1,10})$/u', $name, $matches) === 1) {
            [, $stem, $suffix] = $matches;
        }

        $number = $this->counts[$key];

        do {
            $number++;
            $candidate = $stem.' ('.$number.')'.$suffix;
        } while (isset($this->taken[mb_strtolower($candidate)]));

        $this->counts[$key] = $number;
        $this->taken[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * NFC, no control characters (C0, C1, DEL), no bidirectional embeddings, overrides, isolates or marks, no
     * path separators and no run of dots, so no `..` segment survives.
     */
    private static function clean(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);

        if ($normalized === false) {
            // Not valid UTF-8: drop the bytes that are not.
            $normalized = Normalizer::normalize((string) mb_convert_encoding($name, 'UTF-8', 'UTF-8'), Normalizer::FORM_C);
            $normalized = $normalized === false ? '' : $normalized;
        }

        $normalized = (string) preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $normalized);
        $normalized = str_replace(['/', '\\'], '-', $normalized);
        $normalized = (string) preg_replace('/\.{2,}/', '.', $normalized);

        return trim($normalized);
    }

    /**
     * The lower-case extension when it is a plain `[a-z0-9]{1,10}` token, otherwise null.
     */
    private static function plainExtension(?string $extension): ?string
    {
        if ($extension === null) {
            return null;
        }

        $extension = strtolower(ltrim($extension, '.'));

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : null;
    }
}
