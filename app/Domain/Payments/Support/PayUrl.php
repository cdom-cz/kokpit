<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Payments\Queries\PayPageState;

/**
 * The stable pay URL printed on the PDF and sent in the e-mail (D-01): `<app.url>/pay/<token>`.
 *
 * The host comes from the configured `app.url` only, never from the request, so a spoofed Host header cannot put a
 * foreign address on a document (research Pitfall 9, T-11-10). The URL is the same for every caller and for every
 * regeneration of the PDF. The token is 32 characters, so the URL stays within the 80 characters of the PDF layout for
 * any host up to 43 characters.
 */
final readonly class PayUrl
{
    public static function for(string $token): string
    {
        $base = config('app.url');

        return rtrim(is_string($base) ? $base : '', '/').'/pay/'.$token;
    }

    /**
     * The pay URL of an invoice the caller has already loaded through its own scope, or null while the document does
     * not offer card payment: its card switch is off or it has no token (Phase 14 D-03, D-10; R-14-05-1). A token
     * outlives a switch-off since Phase 14, so the token alone no longer says that card payment is offered, and every
     * place that prints the URL (the PDF, the e-mail block, the Partner and Admin buttons) asks this one gate. It reads
     * the switch and the token of the Admin-only row in one query, as the pay page and the PDF route do, and never
     * decides access. The public pay page keeps resolving a token to its document without this gate
     * ({@see PayPageState}), so a URL printed earlier still answers with its information state.
     */
    public static function forInvoice(Invoice $invoice): ?string
    {
        $row = InvoiceAdmin::query()->where('invoice_id', $invoice->getKey())->first(['card_payment_enabled', 'pay_token']);

        if (! $row instanceof InvoiceAdmin || ! $row->card_payment_enabled) {
            return null;
        }

        $token = $row->pay_token;

        return is_string($token) && $token !== '' ? self::for($token) : null;
    }
}
