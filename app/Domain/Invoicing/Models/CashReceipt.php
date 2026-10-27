<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash receipt (PPD, "prijmovy pokladni doklad"): the receiver's accounting document for cash taken in, issued by
 * Kokpit for every cash payment it records, in its own gap-free series (Phase 15). The row holds exactly what the
 * receipt prints, its lock and cancel state and three opaque links (the invoice, the payment, the stored PDF).
 *
 * A Partner reads a receipt exactly when it belongs to the own client, the paid document is issued and the client is
 * not archived (D-13). A cancelled receipt stays visible to the Partner (AS-4); the reason of the cancel never is,
 * because it is not on the row: cancel and unlock reasons and their causers live only in the Admin-only activity log
 * written by the Actions (AS-16). The client row is checked with a plain EXISTS subquery, not through the Client
 * model, which is closed to Partners, as {@see Invoice} does. A Partner never writes a receipt, and reaches no payment,
 * rate or finance row through it: {@see CashReceipt::payment()} targets the Admin-only {@see InvoicePayment}, so it is
 * empty for a Partner.
 *
 * Immutability is the one of the database guard (migration of plan 15-02), for every writer: the number, paid document
 * number, amount, currency, issue day, language, appearance snapshot, invoice, client and creation time never change; a
 * receipt is never deleted. While open, the receipt date, purpose and the two party snapshots are editable; a locked
 * receipt accepts no edit; `locked -> open` only through the sanctioned unlock; the cancel is set once. Nothing is
 * fillable: every column is set with `forceFill` by the Actions.
 *
 * The model logs nothing through model events (no `LogsAllowlistedActivity`): the history of a receipt is the explicit
 * rows of the Actions, written with fixed keys, so no printed or free text can reach the log by an attribute change.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $client_id
 * @property string|null $invoice_payment_id null only for a cancelled receipt whose payment was deleted
 * @property string $number
 * @property string $document_number the number of the paid invoice or proforma, copied once at issue
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $received_on
 * @property int $amount_minor
 * @property string $currency
 * @property string $purpose
 * @property InvoiceLanguage $language
 * @property array<string, mixed> $supplier_snapshot
 * @property array<string, mixed> $customer_snapshot
 * @property array<string, mixed> $appearance_snapshot
 * @property CashReceiptLockState $lock_state
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $pdf_document_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class CashReceipt extends KokpitModel implements PartnerIsolated
{
    use IsolatesPartners;

    protected $table = 'cash_receipts';

    /**
     * Of the own client, for an issued document, and the client is not archived.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $table = $this->getTable();

        $query
            ->where("{$table}.client_id", $clientId)
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')
                ->from('invoices')
                ->whereColumn('invoices.id', "{$table}.invoice_id")
                ->where('invoices.state', InvoiceState::Issued->value))
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')
                ->from('clients')
                ->whereColumn('clients.id', "{$table}.client_id")
                ->whereNull('clients.deleted_at'));
    }

    /**
     * The printed amount, in the currency of the receipt.
     */
    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function isLocked(): bool
    {
        return $this->lock_state === CashReceiptLockState::Locked;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * The paid invoice or proforma.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The cash payment the receipt was issued for. Admin-only target: null for a Partner. Null as well for a
     * cancelled receipt whose payment was deleted (D-08).
     *
     * @return BelongsTo<InvoicePayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    /**
     * The stored PDF of the receipt, once generated.
     *
     * @return BelongsTo<Document, $this>
     */
    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'pdf_document_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'received_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'number' => 'string',
            'document_number' => 'string',
            'supplier_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'appearance_snapshot' => 'array',
            'language' => InvoiceLanguage::class,
            'lock_state' => CashReceiptLockState::class,
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
