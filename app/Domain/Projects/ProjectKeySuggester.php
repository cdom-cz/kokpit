<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use Closure;
use Illuminate\Support\Str;

/**
 * Suggests a short key (two to six capital letters) from a name (D-14, PR-02).
 *
 * Pure: it reads no storage. The caller says which keys are taken through a
 * closure, and decides what "taken" means (the project form counts archived
 * projects too).
 *
 * The name is transliterated to ASCII and upper-cased first, so letters are
 * taken and counted after diacritics are gone ("Účetnictví" gives "UCET").
 * Everything that is not a letter separates words. A single-letter word is dropped
 * when at least two longer words remain (Czech prepositions). The base key is
 * the initials of up to six words, or for a single word its first four letters
 * (the whole word when shorter); fewer than two letters give "PRJ".
 *
 * The candidates are tried in one fixed order, so the same name and the same
 * taken keys always give the same suggestion:
 *  1. the base key;
 *  2. several words: each word in turn (first word first) extended by its
 *     following letters, the other words keeping their initial, while the key
 *     stays within six letters; single word: its first 3, 5 and 6 letters;
 *  3. the base key cut to five letters plus A to Z.
 * The first candidate that is not taken is returned; null when all are taken.
 */
final class ProjectKeySuggester
{
    private const int MAX_LENGTH = 6;

    private const int SINGLE_WORD_BASE = 4;

    private const string FALLBACK = 'PRJ';

    /**
     * @param  Closure(string): bool  $isTaken
     */
    public static function suggest(string $name, Closure $isTaken): ?string
    {
        foreach (self::candidates($name) as $candidate) {
            if (! $isTaken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every candidate in trial order, without repeats.
     *
     * @return list<string>
     */
    private static function candidates(string $name): array
    {
        $words = self::words($name);
        $base = self::base($words);

        $candidates = [$base];

        if (count($words) === 1) {
            foreach ([3, 5, 6] as $length) {
                if (strlen($words[0]) >= $length) {
                    $candidates[] = substr($words[0], 0, $length);
                }
            }
        } elseif (count($words) > 1) {
            array_push($candidates, ...self::extendedInitials(array_slice($words, 0, self::MAX_LENGTH)));
        }

        $stem = substr($base, 0, self::MAX_LENGTH - 1);

        foreach (range('A', 'Z') as $letter) {
            $candidates[] = $stem.$letter;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  list<string>  $words
     */
    private static function base(array $words): string
    {
        $base = match (true) {
            count($words) > 1 => implode('', array_map(static fn (string $word): string => $word[0], array_slice($words, 0, self::MAX_LENGTH))),
            count($words) === 1 => substr($words[0], 0, self::SINGLE_WORD_BASE),
            default => '',
        };

        return strlen($base) >= 2 ? $base : self::FALLBACK;
    }

    /**
     * The initials with one word at a time written out further, first word first.
     *
     * @param  list<string>  $words  at most six
     * @return list<string>
     */
    private static function extendedInitials(array $words): array
    {
        $initials = array_map(static fn (string $word): string => $word[0], $words);
        $room = self::MAX_LENGTH - count($words) + 1;
        $candidates = [];

        foreach ($words as $index => $word) {
            for ($length = 2; $length <= min(strlen($word), $room); $length++) {
                $parts = $initials;
                $parts[$index] = substr($word, 0, $length);
                $candidates[] = implode('', $parts);
            }
        }

        return $candidates;
    }

    /**
     * The upper-case ASCII words of a name.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $ascii = Str::upper(Str::ascii($name));
        $words = preg_split('/[^A-Z]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return [];
        }

        $longer = array_filter($words, static fn (string $word): bool => strlen($word) > 1);

        if (count($longer) >= 2) {
            $words = array_values($longer);
        }

        return $words;
    }
}
