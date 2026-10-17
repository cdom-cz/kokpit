<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\URL;

/**
 * The only way the PDF of an issued document gets a link (IN-09, D-21; ruling R-38-1). Like the link of a file
 * (DocumentUrl) it points at the application, is signed, lives for `kokpit.documents.signed_url_ttl_seconds` and
 * carries the id of the requesting user under the signature, so a copy of it is useless to anybody else. The path
 * names the invoice, not the stored document: the document id never leaves the application.
 *
 * A screen never renders such a link into its HTML: an action builds it at click time and redirects.
 */
final class InvoicePdfUrl
{
    public const string ROUTE = 'filament.admin.files.invoice';

    public static function download(Invoice $invoice, User $user): string
    {
        $ttl = max(1, (int) config('kokpit.documents.signed_url_ttl_seconds'));

        return URL::temporarySignedRoute(self::ROUTE, now()->addSeconds($ttl), [
            'invoice' => $invoice->getKey(),
            'u' => $user->getKey(),
        ]);
    }
}
