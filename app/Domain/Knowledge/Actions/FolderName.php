<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use Illuminate\Validation\ValidationException;

/**
 * The one rule for the name of a knowledge folder (R-19-05-1), shared by creating and renaming.
 *
 * The name is trimmed and may have 1 to MAX_LENGTH characters. A NUL byte is refused with a field error, never
 * stripped: the PostgreSQL driver would cut the text off at the NUL without a message. The check runs on the raw
 * text, because trim() strips a NUL at either end and would hide it. The database CHECK (a name with at least one
 * non-space character) is the backstop for blank names.
 */
final class FolderName
{
    public const int MAX_LENGTH = 100;

    /**
     * @throws ValidationException a field error on `name`
     */
    public static function from(mixed $name): string
    {
        if (is_string($name) && str_contains($name, "\0")) {
            throw ValidationException::withMessages(['name' => __('knowledge.errors.nul_character')]);
        }

        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.name_required')]);
        }

        if (mb_strlen($name) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['name' => __('knowledge.folders.errors.name_too_long', ['max' => self::MAX_LENGTH])]);
        }

        return $name;
    }
}
