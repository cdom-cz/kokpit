<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;

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
     * The pay URL of an invoice the caller has already loaded through its own scope, or null when the document has no
     * token. It reads the Admin-only row as the pay page and the PDF route do, and never decides access.
     */
    public static function forInvoice(Invoice $invoice): ?string
    {
        $token = InvoiceAdmin::query()->where('invoice_id', $invoice->getKey())->value('pay_token');

        return is_string($token) && $token !== '' ? self::for($token) : null;
    }
}
