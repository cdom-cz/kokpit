<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use Illuminate\Validation\ValidationException;

/**
 * The one rule for the typed reason of an irreversible knowledge action (the redaction of a version and the delete of
 * an image for good, D-12, D-19): required, free of NUL characters and at most a given number of characters.
 *
 * The reason may hold what the Admin pasted, a secret included (owner decision OD-1). This helper therefore returns the
 * LENGTH of the trimmed text and never the text itself, and every refusal names the field and a number only. The caller
 * drops the text right after the call.
 */
final class ReasonText
{
    /**
     * The length of the trimmed reason.
     *
     * @param  mixed  $reason  the typed value; anything but a non-blank string is refused as missing
     * @param  int  $max  the longest trimmed length that is accepted
     * @param  string  $requiredKey  the translation key of the message for a missing reason
     * @param  string  $tooLongKey  the translation key of the message for a reason above the maximum (gets `max`)
     *
     * @throws ValidationException a field error on `reason`
     */
    public static function length(mixed $reason, int $max, string $requiredKey, string $tooLongKey): int
    {
        if (is_string($reason) && str_contains($reason, "\0")) {
            throw ValidationException::withMessages(['reason' => __('knowledge.errors.nul_character')]);
        }

        $trimmed = is_string($reason) ? trim($reason) : '';

        if ($trimmed === '') {
            throw ValidationException::withMessages(['reason' => __($requiredKey)]);
        }

        $length = mb_strlen($trimmed);

        if ($length > $max) {
            throw ValidationException::withMessages(['reason' => __($tooLongKey, ['max' => $max])]);
        }

        return $length;
    }
}
