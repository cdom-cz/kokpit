<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Invoicing\Support\SnapshotInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Corrects an open cash receipt (Phase 15; D-10, D-11, ruling R-15-13-1; threat T-15-29), the twin of
 * {@see UpdateIssuedDocument}. The Admin edits the receipt date (`received_on`), the purpose and the printed supplier
 * and customer lines. The amount, the currency, the number and the language never change here: only these four keys are
 * read from the data, any other key is ignored, and the database guard of the receipt refuses the frozen columns anyway.
 * The amount and the currency change only through the payment.
 *
 * Everything happens in one database transaction. The order is deliberate:
 *
 * 1. the actor is authorised before the database is touched;
 * 2. the invoice row is locked, then the receipt row, the lock order of every writer of a receipt (cancel, send, edit),
 *    and the refusals are judged on the locked row, all under `receipt`: a cancelled receipt (`cancelled`), a locked
 *    receipt (`locked`, it is opened only by {@see UnlockCashReceipt}) and a receipt whose payment confirmation is being
 *    sent right now (`sending`, research Pitfall 4: the e-mail would carry the old or a half-replaced PDF). The
 *    confirmation row is locked too, so the send job cannot claim it between this check and the commit;
 * 3. the input is checked: `received_on` is a calendar day (`Y-m-d`) not after the issue day, `purpose` has 1 to 500
 *    characters, the party arrays pass {@see SnapshotInput} (a field that is absent keeps its stored value);
 * 4. an edit that changes nothing returns without a write, a new PDF or an audit row;
 * 5. the receipt is saved through the model, the stored PDF is created again from the saved row
 *    ({@see StoreCashReceiptPdf}, so the PDF always shows what the row says, D-11) and one audit row
 *    ({@see CashReceiptAudit::edited()}) records the values before and after in the Admin-only log. A failure in the PDF
 *    rolls the whole edit back.
 *
 * A Partner is refused by the policy before anything else happens.
 */
final readonly class UpdateCashReceipt
{
    private const int PURPOSE_MAX = 500;

    public function __construct(private StoreCashReceiptPdf $pdf) {}

    /**
     * @param  array<array-key, mixed>  $data  `received_on`, `purpose`, `customer`, `supplier`; other keys are ignored
     *
     * @throws ValidationException `received_on`, `purpose`, `customer.<field>` or `supplier.<field>` for a value that
     *                             is not accepted; `receipt` for a receipt that cannot be edited now
     */
    public function handle(User $actor, CashReceipt $receipt, array $data): CashReceipt
    {
        Gate::forUser($actor)->authorize('update', $receipt);

        return DB::transaction(function () use ($actor, $receipt, $data): CashReceipt {
            Invoice::query()->whereKey($receipt->invoice_id)->lockForUpdate()->first();
            $locked = CashReceipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            $this->refuse($locked);

            $before = CashReceiptAudit::snapshot($locked);
            $locked->forceFill($this->changes($locked, $data));
            $after = CashReceiptAudit::snapshot($locked);

            if ($after === $before) {
                return $locked->refresh();
            }

            $locked->save();

            $this->pdf->handle($actor, $locked);

            CashReceiptAudit::edited($locked, $actor, $before, $after);

            return $locked->refresh();
        });
    }

    /**
     * Throws for a receipt that cannot be edited now.
     *
     * @throws ValidationException
     */
    private function refuse(CashReceipt $locked): void
    {
        $message = match (true) {
            $locked->isCancelled() => 'cancelled',
            $locked->isLocked() => 'locked',
            $this->isSending($locked) => 'sending',
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['receipt' => __('cash_receipts.errors.'.$message)]);
        }
    }

    /**
     * Whether the confirmation e-mail of the payment of the receipt is being sent. The row is locked for the rest of the
     * transaction.
     */
    private function isSending(CashReceipt $locked): bool
    {
        if ($locked->invoice_payment_id === null) {
            return false;
        }

        $confirmation = PaymentConfirmation::query()
            ->where('invoice_payment_id', $locked->invoice_payment_id)
            ->lockForUpdate()
            ->first();

        return $confirmation !== null && $confirmation->state === PaymentConfirmationState::Sending;
    }

    /**
     * The checked column values for the keys that were given.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function changes(CashReceipt $locked, array $data): array
    {
        $changes = [];

        if (array_key_exists('received_on', $data)) {
            $changes['received_on'] = $this->receivedOn($locked, $data['received_on']);
        }

        if (array_key_exists('purpose', $data)) {
            $changes['purpose'] = $this->purpose($data['purpose']);
        }

        if (isset($data['customer']) && is_array($data['customer'])) {
            $changes['customer_snapshot'] = SnapshotInput::customer($data['customer'], $locked->customer_snapshot);
        }

        if (isset($data['supplier']) && is_array($data['supplier'])) {
            $changes['supplier_snapshot'] = SnapshotInput::supplier($data['supplier'], $locked->supplier_snapshot);
        }

        return $changes;
    }

    /**
     * The receipt date as a `Y-m-d` day that is a real calendar day and not after the issue day.
     *
     * @throws ValidationException
     */
    private function receivedOn(CashReceipt $locked, mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        $day = $text === '' ? false : CarbonImmutable::createFromFormat('!Y-m-d', $text);

        if ($day === false || $day->format('Y-m-d') !== $text || $text > $locked->issued_on->format('Y-m-d')) {
            throw ValidationException::withMessages(['received_on' => __('cash_receipts.errors.received_on_invalid')]);
        }

        return $text;
    }

    /**
     * The purpose, trimmed, 1 to 500 characters.
     *
     * @throws ValidationException
     */
    private function purpose(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        $length = mb_strlen($text);

        if ($length < 1 || $length > self::PURPOSE_MAX) {
            throw ValidationException::withMessages(['purpose' => __('cash_receipts.errors.purpose_invalid')]);
        }

        return $text;
    }
}
