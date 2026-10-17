<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Shared\Money\Money;
use InvalidArgumentException;
use OverflowException;

/**
 * The amounts of a draft, computed on the server and never taken from a request (IN-02, D-03, D-08, D-09).
 *
 * An ordinary line is the unit price times the quantity, rounded once by {@see Money::times()}, the rule the
 * database CHECK of `invoice_items` repeats. A document total is the plain sum of the stored line totals: it never
 * recomputes a line from its unit price, so a line converted from another currency keeps its single-rounded amount
 * (plan 10-01 R-01-1). The VAT is zero, because only the non-payer mode exists and no code path writes a VAT amount
 * (IN-08).
 */
final class DraftTotals
{
    /**
     * The amount of an ordinary line.
     *
     * @throws OverflowException when the amount does not fit into an integer
     */
    public static function lineTotal(Money $unitPrice, InvoiceQuantity $quantity): Money
    {
        return $unitPrice->times($quantity->toDecimal());
    }

    /**
     * The subtotal, the VAT and the total of a document from its stored line totals.
     *
     * @param  iterable<Money>  $lineTotals  in the currency of the document
     * @return array{subtotal: Money, vat: Money, total: Money}
     *
     * @throws InvalidArgumentException when a line is in another currency or the currency is invalid
     * @throws OverflowException when the sum does not fit into an integer
     */
    public static function for(iterable $lineTotals, string $currency): array
    {
        $subtotal = Money::zero($currency);

        foreach ($lineTotals as $lineTotal) {
            $subtotal = $subtotal->plus($lineTotal);
        }

        $vat = Money::zero($currency);

        return ['subtotal' => $subtotal, 'vat' => $vat, 'total' => $subtotal->plus($vat)];
    }
}
