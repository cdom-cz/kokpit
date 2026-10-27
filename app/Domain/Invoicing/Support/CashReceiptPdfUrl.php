<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\CashReceipt;
use Illuminate\Support\Facades\URL;

/**
 * The only way the PDF of a cash receipt gets a link (Phase 15, D-13). Like the link of an invoice PDF
 * ({@see InvoicePdfUrl}) it points at the application, is signed, lives for `kokpit.documents.signed_url_ttl_seconds`
 * and carries the id of the requesting user under the signature, so a copy of it is useless to anybody else. The path
 * names the receipt, not the stored document: the document id never leaves the application.
 *
 * A screen never renders such a link into its HTML: an action builds it at click time and redirects.
 */
final class CashReceiptPdfUrl
{
    public const string ROUTE = 'filament.admin.files.cash-receipt';

    public static function download(CashReceipt $receipt, User $user): string
    {
        $ttl = max(1, (int) config('kokpit.documents.signed_url_ttl_seconds'));

        return URL::temporarySignedRoute(self::ROUTE, now()->addSeconds($ttl), [
            'cashReceipt' => $receipt->getKey(),
            'u' => $user->getKey(),
        ]);
    }
}
