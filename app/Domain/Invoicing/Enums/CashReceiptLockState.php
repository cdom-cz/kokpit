<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

/**
 * The lock of a cash receipt (D-10): `Open` while its date, purpose and snapshots may still be corrected, `Locked`
 * once the confirmation e-mail was sent. Stored in `cash_receipts.lock_state`.
 *
 * A separate enum from {@see InvoiceLockState} on purpose, so the unlock path architecture test of the invoices stays
 * untouched. There is no label: the state is shown only through the badge keys of the payments tab, so no enum label
 * exists to drift.
 */
enum CashReceiptLockState: string
{
    case Open = 'open';
    case Locked = 'locked';
}
