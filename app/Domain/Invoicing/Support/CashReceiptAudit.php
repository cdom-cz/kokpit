<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\IssueCashReceipt;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Shared\Models\Activity;

/**
 * The explicit audit rows of a cash receipt (Phase 15; R-15-08-3, assumption AS-16 confirmed by the owner). A receipt
 * logs nothing through model events, so its whole history is the rows written here, each by the one place that moves
 * the receipt, in the same transaction, to the `invoice` log with the receipt as the subject.
 *
 * - {@see self::issued()}: {@see IssueCashReceipt} stored the receipt; the Admin is the causer;
 * - {@see self::edited()}: the Admin corrected the receipt date, the purpose or a printed party line, with the values
 *   before and after (the printed texts are logged on purpose, like {@see InvoiceAudit::edited()});
 * - {@see self::locked()}: the successful send of the receipt locked it;
 * - {@see self::lockedManually()}: the Admin locked an open receipt by hand, without a send (Phase 16, D-02);
 * - {@see self::unlocked()}: the Admin opened a locked receipt with a reason;
 * - {@see self::cancelled()}: the Admin cancelled the receipt with a reason; {@see self::cancelReason()} reads it back.
 *
 * The reasons are free text typed by the Admin and the edited values are printed texts. They go on purpose, and only,
 * to this Admin-only activity log (AS-16, the Phase 10.1 D-13 precedent): the receipt row itself holds neither, so a
 * Partner who reads the receipt never reads them, and a Partner reads no activity row. Each writer writes exactly one
 * row, and the payload is a fixed allowlist of keys: no e-mail address of a recipient, no body, no bank data.
 */
final readonly class CashReceiptAudit
{
    public const string LOG = InvoiceAudit::LOG;

    /** The customer lines of the receipt that are audited and printed. */
    private const array CUSTOMER_KEYS = ['name', 'street', 'city', 'postal_code', 'country', 'company_id', 'vat_id'];

    /** The supplier lines of the receipt that are audited and printed. */
    private const array SUPPLIER_KEYS = ['name', 'street', 'city', 'postal_code', 'country', 'company_id', 'vat_id', 'email', 'phone', 'website', 'registration_note'];

    /**
     * The receipt was issued for a cash payment, by the Admin who recorded the payment. The purpose is deliberately
     * not part of the payload: it is a printed text that the Admin may edit later, and the edit is what is logged.
     */
    public static function issued(CashReceipt $receipt, User $actor): void
    {
        activity(self::LOG)
            ->performedOn($receipt)
            ->causedBy($actor)
            ->event('cash_receipt_issued')
            ->withChanges(['attributes' => [
                'number' => $receipt->number,
                'document_number' => $receipt->document_number,
                'invoice_id' => $receipt->invoice_id,
                'amount_minor' => $receipt->amount_minor,
                'currency' => $receipt->currency,
                'received_on' => $receipt->received_on->format('Y-m-d'),
            ]])
            ->log('cash_receipt_issued');
    }

    /**
     * The editable values of a receipt, flattened to `key => scalar`: the receipt date, the purpose and the printed
     * customer and supplier lines (`customer.<key>`, `supplier.<key>`). Read before and after an edit.
     *
     * @return array<string, string|null>
     */
    public static function snapshot(CashReceipt $receipt): array
    {
        $values = [
            'received_on' => $receipt->received_on->format('Y-m-d'),
            'purpose' => $receipt->purpose,
        ];

        foreach (['customer' => self::CUSTOMER_KEYS, 'supplier' => self::SUPPLIER_KEYS] as $party => $keys) {
            $stored = $party === 'customer' ? $receipt->customer_snapshot : $receipt->supplier_snapshot;

            foreach ($keys as $key) {
                $value = $stored[$key] ?? null;
                $values["{$party}.{$key}"] = is_scalar($value) ? (string) $value : null;
            }
        }

        return $values;
    }

    /**
     * One row for an edit, holding only the keys whose value changed between `$before` and `$after` (both
     * {@see self::snapshot()} shapes). Nothing is written for an edit that changed nothing, and a key outside the
     * snapshot is never written.
     *
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     */
    public static function edited(CashReceipt $receipt, User $actor, array $before, array $after): void
    {
        $allowed = array_keys(self::snapshot($receipt));
        $old = [];
        $new = [];

        foreach ($allowed as $key) {
            $was = $before[$key] ?? null;
            $is = $after[$key] ?? null;

            if ($was !== $is) {
                $old[$key] = $was;
                $new[$key] = $is;
            }
        }

        if ($new === []) {
            return;
        }

        activity(self::LOG)
            ->performedOn($receipt)
            ->causedBy($actor)
            ->event('cash_receipt_edited')
            ->withChanges(['attributes' => $new, 'old' => $old])
            ->log('cash_receipt_edited');
    }

    /**
     * A successful send moved the receipt from open to locked. The causer is the user who sent it, when known.
     */
    public static function locked(CashReceipt $receipt, ?User $causer): void
    {
        $logger = activity(self::LOG)->performedOn($receipt);

        if ($causer instanceof User) {
            $logger->causedBy($causer);
        }

        $logger
            ->event('cash_receipt_locked')
            ->withChanges([
                'attributes' => ['lock_state' => 'locked'],
                'old' => ['lock_state' => 'open'],
            ])
            ->log('cash_receipt_locked');
    }

    /**
     * The Admin locked an open receipt by hand, without a send (Phase 16, D-02). The Admin is the causer; there is no
     * reason, so the payload is the move from open to locked only.
     */
    public static function lockedManually(CashReceipt $receipt, User $actor): void
    {
        activity(self::LOG)
            ->performedOn($receipt)
            ->causedBy($actor)
            ->event('cash_receipt_locked_manually')
            ->withChanges([
                'attributes' => ['lock_state' => 'locked'],
                'old' => ['lock_state' => 'open'],
            ])
            ->log('cash_receipt_locked_manually');
    }

    /**
     * The Admin opened a locked receipt with a reason (already trimmed and length-checked by the Action).
     */
    public static function unlocked(CashReceipt $receipt, User $actor, string $reason): void
    {
        activity(self::LOG)
            ->performedOn($receipt)
            ->causedBy($actor)
            ->event('cash_receipt_unlocked')
            ->withChanges([
                'attributes' => ['lock_state' => 'open', 'unlock_reason' => $reason],
                'old' => ['lock_state' => 'locked'],
            ])
            ->log('cash_receipt_unlocked');
    }

    /**
     * The Admin cancelled the receipt with a reason (already trimmed and length-checked by the Action). The reason is
     * written here only; the receipt row has no column for it.
     */
    public static function cancelled(CashReceipt $receipt, User $actor, string $reason): void
    {
        activity(self::LOG)
            ->performedOn($receipt)
            ->causedBy($actor)
            ->event('cash_receipt_cancelled')
            ->withChanges([
                'attributes' => ['cancel_reason' => $reason],
            ])
            ->log('cash_receipt_cancelled');
    }

    /**
     * The reason of the cancel of a receipt, read back from its `cash_receipt_cancelled` row; null when the receipt
     * was not cancelled through the Action. For the Admin only: the log is closed to a Partner.
     */
    public static function cancelReason(CashReceipt $receipt): ?string
    {
        $row = Activity::query()
            ->where('subject_type', 'cash_receipt')
            ->where('subject_id', $receipt->getKey())
            ->where('event', 'cash_receipt_cancelled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $attributes = $row?->attribute_changes?->get('attributes');
        $reason = is_array($attributes) ? ($attributes['cancel_reason'] ?? null) : null;

        return is_string($reason) ? $reason : null;
    }
}
