<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;

/**
 * The pay-link block appended to the e-mail of a document (PA-02; D-03, ruling R11-05-3), and the one predicate that
 * says whether it will be appended, which the e-mail dialog reads too.
 *
 * The block is a blank line, the lead line in the language of the document and the stable pay URL on its own line. It
 * is added at send time, after the text the Admin confirmed, so editing or clearing the text cannot remove it, and it
 * never waits for the Stripe link job and never contains a Stripe URL. It applies when the document is an issued
 * invoice or proforma issued with card payment on (it has a pay token), is not cancelled and still owes money, so a
 * resend of a paid or cancelled document carries none. The caller must be the Admin or a system run, as
 * {@see InvoiceBalances} requires.
 */
final readonly class PayLinkBlock
{
    public static function applies(Invoice $invoice): bool
    {
        return self::url($invoice) !== null;
    }

    /**
     * The block to append to the plain-text body, or null when it does not apply.
     */
    public static function forMail(Invoice $invoice): ?string
    {
        $url = self::url($invoice);

        if ($url === null) {
            return null;
        }

        return "\n\n".trans('invoice_mail.pay_block_lead', [], $invoice->language->value)."\n".$url;
    }

    private static function url(Invoice $invoice): ?string
    {
        if (! $invoice->isIssued() || ! $invoice->kind->carriesPayment() || $invoice->isCancelled()) {
            return null;
        }

        $url = PayUrl::forInvoice($invoice);

        if ($url === null) {
            return null;
        }

        $remaining = app(InvoiceBalances::class)->for([(string) $invoice->getKey()])[(string) $invoice->getKey()]['remaining'] ?? null;

        return $remaining !== null && $remaining > 0 ? $url : null;
    }
}
