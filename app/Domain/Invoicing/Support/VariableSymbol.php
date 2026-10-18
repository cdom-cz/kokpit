<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Settings\Numbering\DocumentKind;
use LogicException;

/**
 * The variable symbol of a payment QR (ruling R-07-2).
 *
 * Pure PHP, no framework. The symbol is the document number itself: the number
 * pattern of an invoice or a proforma is digits only and at most ten characters
 * ({@see DocumentKind::carriesVariableSymbol()}), which is exactly what SPAYD
 * allows. A credit note carries neither a symbol nor a QR. A number that is not
 * 1 to 10 digits fails here instead of reaching the QR.
 *
 * Matching a payment by variable symbol must scope by document kind, because an
 * invoice and a proforma may still share a symbol under custom patterns.
 */
final class VariableSymbol
{
    private const string DIGITS = '/^[0-9]{1,10}$/D';

    /**
     * @return string|null the symbol, or null for a credit note
     *
     * @throws LogicException for an invoice or proforma number that is not 1 to 10 digits, or for a task
     */
    public static function fromNumber(DocumentKind $kind, string $number): ?string
    {
        if ($kind === DocumentKind::CreditNote) {
            return null;
        }

        if (! $kind->carriesVariableSymbol()) {
            throw new LogicException("A {$kind->value} has no variable symbol.");
        }

        if (preg_match(self::DIGITS, $number) !== 1) {
            throw new LogicException("The {$kind->value} number is not a valid variable symbol: it must be 1 to 10 digits.");
        }

        return $number;
    }
}
