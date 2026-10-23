<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;

/**
 * The explicit audit rows of an issued document (IN-15; D-13, rulings R-10.1-02-2 and R-10.1-04-8). The lock state and
 * the corrected values are not on the logged-attribute allowlist of the invoice as far as a post-send edit is
 * concerned, so a model save of them writes no row by itself: the one place that moves them writes the row here, in the
 * same transaction, through the activity log of the Admin.
 *
 * - {@see self::locked()}: the successful send of the document e-mail locked the document ({@see SendInvoiceEmail});
 *   the sender is the causer;
 * - {@see self::unlocked()}: the Admin opened the document ({@see UnlockInvoice}); the reason is free text written to
 *   the log on purpose (D-13 overrides the rule that free text is never logged, assumption A12);
 * - {@see self::snapshot()}: the flat values of the document an edit can change, read before and after the edit: the
 *   currency, the language, the dates, the printed note, the total, the payment method (`payment_method`), the card
 *   switch (`card_payment`, 1 or 0), the bank fingerprint, the printed customer and supplier lines, every item line and
 *   the number of linked time entries (Phase 14, D-09, ruling R-14-09-3);
 * - {@see self::everSent()}: whether the customer ever received the document, which decides whether an edit is audited;
 * - {@see self::edited()}: an edit after the first send ({@see UpdateIssuedDocument}), with the values before and after.
 *
 * Each writer writes exactly one row (or none), and the payload is a fixed allowlist of keys: no token, no e-mail
 * address of a recipient, no body, no bank data, no bank account id and no internal note. The bank account is represented only by its
 * keyed fingerprint ({@see self::snapshot()}, `bank_fingerprint`), so a change of the account is recorded without the
 * account ever entering the history. The party and item texts are in the payload on purpose (assumption A12, an
 * owner-approved exception to "free text is never logged"). The history is read by the Admin only; a Partner never
 * sees an activity row.
 */
final readonly class InvoiceAudit
{
    public const string LOG = 'invoice';

    /** The customer lines of the snapshot that are audited. */
    private const array CUSTOMER_KEYS = ['name', 'street', 'city', 'postal_code', 'country', 'company_id', 'vat_id'];

    /** The supplier lines of the snapshot that are audited (the VAT payer flag is a setting, not a printed line). */
    private const array SUPPLIER_KEYS = ['name', 'street', 'city', 'postal_code', 'country', 'company_id', 'vat_id', 'email', 'phone', 'website', 'registration_note'];

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

    /**
     * The values of an issued document an edit can change, flattened to `key => scalar`: the currency, the language,
     * the three dates, the printed note, the total, the payment method (`payment_method`, null for a credit note), the
     * card switch (`card_payment`, 1 or 0, null when the Admin-only row is missing), the customer and supplier lines
     * as printed, every line by its position, and the number of linked time entries. The bank snapshot itself, the
     * Admin-only account id, the pay token and the internal note are never part of it (T-10.1-18, T-14-32). The bank account appears only as `bank_fingerprint`, which changes when the
     * account changes and cannot be turned back into it, so the history keeps the fingerprint before and after instead
     * of the account values (owner decision of 2026-10-23; gap 2 of 10.1-VERIFICATION.md, review WR-02).
     *
     * @return array<string, string|int|null>
     */
    public static function snapshot(Invoice $invoice): array
    {
        $values = [
            'currency' => $invoice->currency,
            'language' => $invoice->language->value,
            'issue_date' => $invoice->issue_date?->format('Y-m-d'),
            'taxable_supply_date' => $invoice->taxable_supply_date?->format('Y-m-d'),
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'note' => $invoice->note,
            'total_minor' => $invoice->total_minor,
            'payment_method' => $invoice->payment_method?->value,
            'card_payment' => self::cardPayment($invoice),
            'bank_fingerprint' => self::bankFingerprint($invoice->bank_snapshot),
        ];

        foreach (['customer' => self::CUSTOMER_KEYS, 'supplier' => self::SUPPLIER_KEYS] as $party => $keys) {
            $stored = $party === 'customer' ? $invoice->customer_snapshot : $invoice->supplier_snapshot;

            foreach ($keys as $key) {
                $value = $stored[$key] ?? null;
                $values["{$party}.{$key}"] = is_scalar($value) ? (string) $value : null;
            }
        }

        $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get();

        foreach ($lines as $line) {
            $values["items.{$line->position}.description"] = $line->description;
            $values["items.{$line->position}.quantity"] = $line->quantity;
            $values["items.{$line->position}.unit"] = $line->unit;
            $values["items.{$line->position}.unit_price_minor"] = $line->unit_price_minor;
            $values["items.{$line->position}.line_total_minor"] = $line->line_total_minor;
        }

        $values['entries'] = $invoice->entries()->count();

        return $values;
    }

    /**
     * The card switch of the Admin-only row as 1 or 0, null when the document has no such row. Only the switch is read,
     * never the pay token or the account id.
     */
    private static function cardPayment(Invoice $invoice): ?int
    {
        $switch = InvoiceAdmin::query()->where('invoice_id', $invoice->id)->value('card_payment_enabled');

        return $switch === null ? null : (int) (bool) $switch;
    }

    /**
     * A non-reversible marker of the bank account printed on the document: the first 12 hex characters of an
     * HMAC-SHA256 keyed with the application key over the canonical JSON of the bank snapshot, null when there is no
     * snapshot. It is keyed, not a plain hash, because a Czech account number has a small search space that a plain
     * truncated hash could be brute-forced back through. The keys are sorted recursively before encoding, because the
     * row read from PostgreSQL jsonb and the array taken from the settings carry the same account in different key
     * orders, and the order must not fake a change.
     *
     * @param  array<array-key, mixed>|null  $bank
     */
    private static function bankFingerprint(?array $bank): ?string
    {
        if ($bank === null || $bank === []) {
            return null;
        }

        $json = json_encode(self::sortedByKey($bank), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return substr(hash_hmac('sha256', 'invoice-audit-bank|'.$json, (string) config('app.key')), 0, 12);
    }

    /**
     * The array with the keys of every level sorted; lists keep their order.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function sortedByKey(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values, SORT_STRING);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::sortedByKey($value);
            }
        }

        return $values;
    }

    /**
     * Whether a document e-mail of the document was ever sent successfully. A document that was only queued, was
     * sending or failed never reached the customer, so correcting it is not a change to something the customer holds.
     */
    public static function everSent(Invoice $invoice): bool
    {
        return InvoiceEmail::query()
            ->where('invoice_id', $invoice->id)
            ->where('state', InvoiceEmailState::Sent->value)
            ->exists();
    }

    /**
     * One row for an edit after the first send, holding only the keys whose value changed (`$before` is the
     * {@see self::snapshot()} taken before the write). Nothing is written for a document never sent, which keeps its
     * ordinary model history like a draft, and nothing for an edit that changed nothing.
     *
     * @param  array<string, string|int|null>  $before
     */
    public static function edited(Invoice $invoice, User $actor, array $before): void
    {
        if (! self::everSent($invoice)) {
            return;
        }

        $after = self::snapshot($invoice);
        $old = [];
        $new = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $key = (string) $key;
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
            ->performedOn($invoice)
            ->causedBy($actor)
            ->event('invoice_edited')
            ->withChanges(['attributes' => $new, 'old' => $old])
            ->log('invoice_edited');
    }
}
