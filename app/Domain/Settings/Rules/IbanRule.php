<?php

declare(strict_types=1);

namespace App\Domain\Settings\Rules;

use App\Domain\Settings\Banking\Iban;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes for an IBAN the own check accepts. Spaces and lower case are tolerated
 * on input, because they are normalised before the check and before storage.
 */
final class IbanRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Iban::isValid(Iban::normalise($value))) {
            $fail(__('kokpit.settings.bank.iban_invalid'));
        }
    }
}
