<?php

declare(strict_types=1);

namespace App\Domain\Settings\Numbering;

use InvalidArgumentException;

/**
 * A number pattern the grammar refuses. $reason is the suffix of the Czech
 * message key kokpit.settings.numbering.errors.<reason>:
 * unknown_token, counter_count, month_without_year, literal, too_long,
 * invoice_digits, invoice_length, task_fixed, duplicate_token, unclosed_token.
 */
final class InvalidNumberPattern extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid number pattern: {$reason}.");
    }
}
