<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;

/**
 * The explicit audit rows of the lock of an issued document (IN-15; D-13, ruling R-10.1-02-2). The lock state is not
 * on the logged-attribute allowlist of the invoice, so a model save of it writes no row by itself: the one place that
 * moves it writes the row here, in the same transaction, through the activity log of the Admin.
 *
 * - {@see self::locked()}: the successful send of the document e-mail locked the document ({@see SendInvoiceEmail});
 *   the sender is the causer;
 * - {@see self::unlocked()}: the Admin opened the document ({@see UnlockInvoice}); the reason is free text written to
 *   the log on purpose (D-13 overrides the rule that free text is never logged, assumption A12).
 *
 * Each call writes exactly one row, and nothing else goes into the payload: no token, no e-mail address, no body. The
 * history is read by the Admin only; a Partner never sees an activity row.
 */
final readonly class InvoiceAudit
{
    public const string LOG = 'invoice';

    /**
     * A successful send moved the document from open to locked. The causer is the user who sent it, when known.
     */
    public static function locked(Invoice $invoice, ?User $causer): void
    {
        $logger = activity(self::LOG)->performedOn($invoice);

        if ($causer instanceof User) {
            $logger->causedBy($causer);
        }

        $logger
            ->event('invoice_locked')
            ->withChanges([
                'attributes' => ['lock_state' => 'locked'],
                'old' => ['lock_state' => 'open'],
            ])
            ->log('invoice_locked');
    }

    /**
     * The Admin opened a locked document with a reason (already trimmed and length-checked by the Action).
     */
    public static function unlocked(Invoice $invoice, User $actor, string $reason): void
    {
        activity(self::LOG)
            ->performedOn($invoice)
            ->causedBy($actor)
            ->event('invoice_unlocked')
            ->withChanges([
                'attributes' => ['lock_state' => 'open', 'unlock_reason' => $reason],
                'old' => ['lock_state' => 'locked'],
            ])
            ->log('invoice_unlocked');
    }
}
