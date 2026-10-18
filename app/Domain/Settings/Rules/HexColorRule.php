<?php

declare(strict_types=1);

namespace App\Domain\Settings\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes only for a lower-case colour in the form #rrggbb (T-10-06): the value
 * ends up in a PDF stylesheet, so nothing else is let through.
 *
 * It is implicit on purpose, so an empty value gets the same Czech reason as a
 * malformed one instead of being skipped or answered by a generic "required".
 */
final readonly class HexColorRule implements ValidationRule
{
    public bool $implicit;

    public function __construct()
    {
        $this->implicit = true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^#[0-9a-f]{6}$/D', $value) !== 1) {
            $fail(__('kokpit.settings.invoicing.errors.accent'));
        }
    }
}
