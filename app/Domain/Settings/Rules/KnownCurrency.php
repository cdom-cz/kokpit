<?php

declare(strict_types=1);

namespace App\Domain\Settings\Rules;

use App\Domain\Shared\Money\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes only for an upper-case ISO 4217 code known to Money.
 */
final class KnownCurrency implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Money::isKnownCurrency($value)) {
            $fail(__('kokpit.settings.defaults.currency_invalid'));
        }
    }
}
