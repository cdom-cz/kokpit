<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Locks an open cash receipt by hand, without any e-mail (Phase 16, D-02, D-03), the twin of {@see LockInvoice} and the
 * counterpart of {@see UnlockCashReceipt} without a reason. It is for the receipt of a cash payment whose confirmation
 * is not sent, for example when the client is handed the receipt in person. The Admin only, for every client: the
 * confirmation in the page is the whole question, and the audit row ({@see CashReceiptAudit::lockedManually()})
 * records who locked it and when.
 *
 * D-02 is rated costly in the phase context: this is the owner-approved second writer of the locked state of a
 * receipt, next to the successful send of the payment confirmation ({@see SendPaymentConfirmation}). The architecture
 * test `CashReceiptUnlockPathTest` names both files. The lock changes no number, amount, currency or snapshot, and it
 * needs no database setting: the row guard lets an open, uncancelled receipt move to locked with nothing else changed.
 *
 * Everything happens in one database transaction, in the lock order of {@see UpdateCashReceipt}: the invoice row first,
 * then the receipt row, and the refusals are judged on the locked receipt row: a cancelled receipt (`cancelled`) and a
 * receipt that is locked already (`already_locked`), both under `receipt`. The state of the confirmation e-mail is not
 * consulted. The send job locks only a receipt that is neither cancelled nor locked, so a later successful send leaves
 * a hand-locked receipt as it is and writes no second lock row; the job takes only the receipt row lock, so the two
 * cannot deadlock.
 *
 * The state changes through a model save, never a bulk query update. A Partner is refused by the policy before
 * anything else happens.
 */
final readonly class LockCashReceipt
{
    /**
     * @throws ValidationException `receipt` for a receipt that cannot be locked by hand
     */
    public function handle(User $actor, CashReceipt $receipt): CashReceipt
    {
        Gate::forUser($actor)->authorize('update', $receipt);

        return DB::transaction(function () use ($actor, $receipt): CashReceipt {
            Invoice::query()->whereKey($receipt->invoice_id)->lockForUpdate()->first();
            $locked = CashReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            $this->refuse($locked);

            $locked->forceFill(['lock_state' => CashReceiptLockState::Locked])->save();

            CashReceiptAudit::lockedManually($locked, $actor);

            return $locked->refresh();
        });
    }

    /**
     * Throws for a receipt the manual lock must not close: a cancelled one and one that is locked already.
     *
     * @throws ValidationException
     */
    private function refuse(CashReceipt $locked): void
    {
        $message = match (true) {
            $locked->isCancelled() => 'cancelled',
            $locked->isLocked() => 'already_locked',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['receipt' => __('cash_receipts.errors.'.$message)]);
        }
    }
}
