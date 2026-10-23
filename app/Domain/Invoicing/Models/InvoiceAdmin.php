<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Documents\Models\Document;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Admin-only half of one billing document (IN-03, IN-09): the internal note, the CNB rate with its unit
 * amount and date, the CZK total, and the link to the stored PDF. One row per document, written while the
 * document is a draft; after issue the database lets only the note, its marker and the PDF link change.
 *
 * A Partner reads no row of this table, not even for an issued invoice of the own client: DeniesPartners and the
 * admin-only policy leave a Partner no row and no ability (R-08-1, research Pattern 1). Neither the CNB figures nor
 * the note nor the PDF link are therefore printed on the Partner-readable `invoices` row.
 *
 * The stored PDF is reached only through {@see InvoiceAdmin::pdfDocument()} (R-06-6): the Admin reads
 * `$invoice->admin->pdfDocument`, system code (the e-mail job, the PDF route of plan 10-38) reads it as system
 * after authorising the invoice, and for a Partner the `admin` relation of the invoice is null. The relation
 * writes nothing to its parent: there is no `$touches`, because an issued `invoices` row refuses even an
 * `updated_at` write (R-04-6).
 *
 * The card switch and the pay token (Phase 11, plan 11-03; opened in Phase 14, D-03, D-09, owner amendment OA-1).
 * `card_payment_enabled` is the Admin's choice on a draft, and at issue it starts as the AND of the draft switch, the
 * global setting and a configured Stripe, for an invoice or a proforma only. It is editable on an open issued
 * `transfer` document and forced on for an `online` one; the lock freezes it. `pay_token` is the 32-character bearer
 * value of the stable pay URL: it is set once (a first token may be set on an open issued document), never changes or
 * disappears afterwards, and outlives a switch-off so a printed pay URL keeps answering; the lock freezes it too. The
 * switch is on the history allowlist; the token never is, because it is a bearer value.
 *
 * `bank_account_id` is the stable id of the chosen entry of the `bank.accounts` setting (Phase 14, D-05), kept apart
 * from the currency. It has no foreign key and is not on the history allowlist: the account of an issued document is
 * audited by its bank fingerprint, and the id says nothing a reader needs. It is Admin-only and frozen by the lock.
 *
 * Only the note is fillable. The invoice, its marker, the CNB trio, the CZK total and the PDF link are set with
 * `forceFill` by the Actions. The history is an allowlist: the marker `internal_note_updated_at` is logged and the
 * note text never is (T-10-22).
 *
 * @property string $id
 * @property string $invoice_id
 * @property string|null $internal_note
 * @property CarbonImmutable|null $internal_note_updated_at
 * @property numeric-string|null $exchange_rate
 * @property int|null $exchange_unit_amount
 * @property CarbonImmutable|null $exchange_rate_date
 * @property int|null $czk_total_minor
 * @property string|null $pdf_document_id
 * @property bool $card_payment_enabled
 * @property string|null $pay_token
 * @property string|null $bank_account_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['internal_note'])]
#[LoggedAttributes([
    'invoice_id',
    'internal_note_updated_at',
    'exchange_rate',
    'exchange_unit_amount',
    'exchange_rate_date',
    'czk_total_minor',
    'pdf_document_id',
    'card_payment_enabled',
])]
final class InvoiceAdmin extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    protected $table = 'invoice_admin';

    /**
     * The CZK total of the document as Money, or null when the document is in CZK or carries no CNB snapshot.
     */
    public function czkTotal(): ?Money
    {
        return $this->czk_total_minor === null ? null : Money::ofMinor($this->czk_total_minor, 'CZK');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The stored PDF of the document, the only access path to it (R-06-6). Null until the PDF is generated and
     * again after its file is deleted.
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
            'internal_note_updated_at' => 'immutable_datetime',
            'exchange_rate' => 'decimal:10',
            'exchange_unit_amount' => 'integer',
            'exchange_rate_date' => 'immutable_date',
            'czk_total_minor' => 'integer',
            'card_payment_enabled' => 'boolean',
        ];
    }
}
