<?php

declare(strict_types=1);

namespace App\Domain\Clients\Rules;

use App\Domain\Clients\Ares\CompanyId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes for a Czech company number the own mod-11 check accepts. It is shared by
 * the client form and the client Actions, and only the callers decide when it
 * applies: a foreign client's company number stays a free string (D-09). An empty
 * value is not checked here, because the field is optional.
 */
final class CompanyIdRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! CompanyId::isValid(trim($value))) {
            $fail(__('kokpit.ares.errors.invalid_id'));
        }
    }
}
