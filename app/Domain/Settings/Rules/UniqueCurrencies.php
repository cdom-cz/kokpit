<?php

declare(strict_types=1);

namespace App\Domain\Settings\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes for a list of bank accounts in which no currency appears twice
 * (D-04). Codes are compared upper case, so the data layer holds even when
 * a write bypasses the form's select of canonical codes.
 */
final class UniqueCurrencies implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $seen = [];

        foreach ($value as $account) {
            $currency = is_array($account) && is_string($account['currency'] ?? null)
                ? mb_strtoupper(trim($account['currency']))
                : '';

            if ($currency === '') {
                continue;
            }

            if (isset($seen[$currency])) {
                $fail(__('kokpit.settings.bank.currency_duplicate', ['currency' => $currency]));

                return;
            }

            $seen[$currency] = true;
        }
    }
}
