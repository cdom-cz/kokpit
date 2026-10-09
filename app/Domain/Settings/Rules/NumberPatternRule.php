<?php

declare(strict_types=1);

namespace App\Domain\Settings\Rules;

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Numbering\NumberPattern;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes for a pattern the number grammar accepts for one document kind; the
 * failure message is the Czech text of the refusal reason.
 */
final readonly class NumberPatternRule implements ValidationRule
{
    public function __construct(private DocumentKind $kind) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('kokpit.settings.numbering.errors.not_text'));

            return;
        }

        try {
            NumberPattern::parse($value, $this->kind);
        } catch (InvalidNumberPattern $e) {
            $fail(__('kokpit.settings.numbering.errors.'.$e->reason));
        }
    }
}
