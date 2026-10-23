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
 * invoice or proforma that offers card payment now (switch on and a pay token), is not cancelled and still owes money, so a
 * resend of a paid or cancelled document carries none. The caller must be the Admin or a system run, as
 * {@see InvoiceBalances} requires.
 *
 * Phase 13 hand-over (Phase 14 owner answer OA-5), done: the block is added only while the document offers card payment
 * now, that is its card switch is on and it has a pay token ({@see PayUrl::forInvoice()}); a token outlives a switch-off.
 * The document e-mail asks for `forHtml()` once per send: the "Pay by card" button of the HTML part and the text block of
 * the plain-text part both come from that one call ({@see self::textBlock()}), so they cannot disagree, and nothing
 * outside this class reads `pay_token`.
 */
final readonly class PayLinkBlock
{
    public static function applies(Invoice $invoice): bool
    {
        return self::url($invoice) !== null;
    }

    /**
     * What the document e-mail shows for card payment: the lead sentence, the stable pay URL and the button label, the
     * texts in the language of the document. Null exactly when {@see self::applies()} is false (R-13-05-1).
     *
     * @return array{lead: string, url: string, label: string}|null
     */
    public static function forHtml(Invoice $invoice): ?array
    {
        $url = self::url($invoice);

        if ($url === null) {
            return null;
        }

        $language = $invoice->language->value;

        return [
            'lead' => (string) trans('invoice_mail.pay_block_lead', [], $language),
            'url' => $url,
            'label' => (string) trans('invoice_mail.pay_button', [], $language),
        ];
    }

    /**
     * The block to append to the plain-text body: a blank line, the lead and the URL on its own line.
     *
     * @param  array{lead: string, url: string, label: string}  $link  the result of {@see self::forHtml()}
     */
    public static function textBlock(array $link): string
    {
        return "\n\n".$link['lead']."\n".$link['url'];
    }

    /**
     * The block to append to the plain-text body, or null when it does not apply.
     */
    public static function forMail(Invoice $invoice): ?string
    {
        $link = self::forHtml($invoice);

        return $link === null ? null : self::textBlock($link);
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
