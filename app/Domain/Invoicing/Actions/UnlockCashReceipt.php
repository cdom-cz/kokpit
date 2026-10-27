<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Opens a locked cash receipt again so the Admin can correct it (Phase 15; D-10, ruling R-15-13-2, assumption AS-16),
 * the twin of {@see UnlockInvoice}. The Admin only, with a reason of 10 to 500 characters, and the only code that sets
 * `kokpit.cash_receipt_unlock`: the architecture test `CashReceiptUnlockPathTest` keeps that literal out of every other
 * application file and migration. There is no automatic relock: the receipt stays open until the next successful send
 * of its payment confirmation ({@see SendPaymentConfirmation}) locks it again.
 *
 * Everything happens in one database transaction. The order is deliberate:
 *
 * 1. the actor is authorised and the reason is trimmed and measured before the database is touched;
 * 2. the receipt row is locked, the lock every writer of the receipt takes first, and the refusals are judged on that
 *    locked row: a cancelled receipt (`cancelled`) and a receipt that is not locked (`not_locked`), both under
 *    `receipt`;
 * 3. the transaction-local setting names this very receipt, the only thing the row guard of the database accepts for the
 *    move from locked to open;
 * 4. the receipt is opened through a model save, never a bulk query update, so the guard sees one row change that
 *    touches the lock only;
 * 5. the setting is cleared again, so it never outlives the sanctioned statement, even inside a wrapping transaction;
 * 6. the audit row ({@see CashReceiptAudit::unlocked()}) records the actor, the time and the reason in the Admin-only
 *    activity log. The reason is on no row a Partner can read.
 *
 * A Partner is refused by the policy before anything else happens.
 */
final readonly class UnlockCashReceipt
{
    public const int REASON_MIN = 10;

    public const int REASON_MAX = 500;

    /**
     * @throws ValidationException `reason` for a reason outside 10 to 500 characters; `receipt` for a receipt that
     *                             cannot be unlocked
     */
    public function handle(User $actor, CashReceipt $receipt, string $reason): CashReceipt
    {
        Gate::forUser($actor)->authorize('update', $receipt);

        $clean = trim($reason);
        $length = mb_strlen($clean);

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => __('cash_receipts.errors.reason_length')]);
        }

        return DB::transaction(function () use ($actor, $receipt, $clean): CashReceipt {
            $locked = CashReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            $this->refuse($locked);

            DB::select("select set_config('kokpit.cash_receipt_unlock', ?, true)", [$locked->id]);

            $locked->forceFill(['lock_state' => CashReceiptLockState::Open])->save();

            DB::select("select set_config('kokpit.cash_receipt_unlock', '', true)");

            CashReceiptAudit::unlocked($locked, $actor, $clean);

            return $locked->refresh();
        });
    }

    /**
     * Throws for a receipt the unlock must not open.
     *
     * @throws ValidationException
     */
    private function refuse(CashReceipt $locked): void
    {
        $message = match (true) {
            $locked->isCancelled() => 'cancelled',
            ! $locked->isLocked() => 'not_locked',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['receipt' => __('cash_receipts.errors.'.$message)]);
        }
    }
}
