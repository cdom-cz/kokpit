<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use Illuminate\Validation\ValidationException;

/**
 * Reads the list of line numbers the Admin typed into the redaction form, for example `3, 7-9` (D-12, VER-05).
 *
 * The input is a comma separated list of single numbers and ranges (`7-9`), counted from 1 like the line numbers the
 * redaction screen shows. The result is the sorted list of unique line numbers, so duplicates and overlapping ranges
 * collapse. Nothing else is accepted: a token that is not a number or a range, a number below 1, a range that runs
 * backwards, a number of more than nine digits and a list longer than MAX_INPUT_LENGTH characters are all
 * `lines_invalid`, and a number above the number of lines of the version is `line_missing` with that number. An empty
 * list is `lines_required`. The messages name the field and a number, never any text of the version.
 */
final class RedactionLines
{
    public const int MAX_INPUT_LENGTH = 1000;

    /**
     * @return list<int> the selected line numbers, 1-based, ascending, unique
     *
     * @throws ValidationException a field error on `lines`
     */
    public static function parse(string $input, int $lineCount): array
    {
        if (trim($input) === '') {
            throw self::error('lines_required');
        }

        if (mb_strlen($input) > self::MAX_INPUT_LENGTH) {
            throw self::error('lines_invalid');
        }

        $selected = [];

        foreach (explode(',', $input) as $token) {
            if (preg_match('/^\s*(\d{1,9})(?:\s*-\s*(\d{1,9}))?\s*$/D', $token, $match) !== 1) {
                throw self::error('lines_invalid');
            }

            $from = (int) $match[1];
            $to = isset($match[2]) ? (int) $match[2] : $from;

            if ($from < 1 || $to < $from) {
                throw self::error('lines_invalid');
            }

            if ($to > $lineCount) {
                throw self::error('line_missing', ['n' => max($from, $lineCount + 1)]);
            }

            for ($number = $from; $number <= $to; $number++) {
                $selected[$number] = $number;
            }
        }

        ksort($selected);

        return array_values($selected);
    }

    /**
     * @param  array<string, int>  $replace
     */
    private static function error(string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['lines' => __('knowledge.redact.errors.'.$key, $replace)]);
    }
}
