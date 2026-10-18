<?php

declare(strict_types=1);

namespace App\Domain\Payments\Queries;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;

/**
 * The one fact of the card-payment machinery a Partner may act on: the stable pay URL of an own document, or nothing
 * (PA-02; D-04, UI-SPEC Access and Visibility, ruling R11-15-1).
 *
 * The link rows and the pay token are Admin-only, so the reader looks at them as system, in the same way as the PDF
 * route of the Partner does for the PDF link. It returns the stable URL when the document is an issued invoice or
 * proforma that is not cancelled, still owes money and whose newest link is active, and null otherwise. It returns no
 * state, amount, link id or failure: a pending, failed or deactivated link is, to a Partner, the same as no link.
 *
 * The reader decides nothing about access. The caller hands it a record the Partner page loaded through the Partner
 * scope; as a second line, the reader loads the record again through the signed-in session's own scope and answers
 * null when that does not find it, so a record loaded in any other way never produces a URL (T-11-53, T-11-54).
 */
final class PartnerPayButton
{
    /**
     * @param  Invoice  $scopedRecord  a document loaded through the Partner-scoped query
     */
    public static function urlFor(Invoice $scopedRecord): ?string
    {
        if (! Invoice::query()->whereKey($scopedRecord->getKey())->exists()) {
            return null;
        }

        return app(PartnerContext::class)->runAsSystem(static function () use ($scopedRecord): ?string {
            if (! PaymentLinkStatus::for($scopedRecord)['payable']) {
                return null;
            }

            return PayUrl::forInvoice($scopedRecord);
        });
    }
}
