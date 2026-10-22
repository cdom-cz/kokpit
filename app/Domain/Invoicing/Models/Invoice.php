<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A billing document: an invoice, a proforma or a credit note (IN-01). The table holds only what the document
 * prints and its links to other documents, so it is the Partner-readable half of the billing data; rates, CNB
 * and CZK figures, payments, the stored PDF and the billing sources live in Admin-only tables (R-05-2).
 *
 * A Partner reads a document exactly when it belongs to the own client, is issued, is one of the three kinds and
 * the client is not archived (IN-09, UI-SPEC U-2, R-05-1). A draft never exists for a Partner, not even as a
 * count. The client row is checked with a plain EXISTS subquery, not through the Client model, which is closed to
 * Partners, so an archived client hides its documents even for a session that is still open.
 *
 * Cancelled has one definition, derived and never stored (D-14, R-04-5): an invoice is cancelled when an issued
 * credit note with `cancels_invoice` names it. {@see Invoice::stornoCreditNote()} finds that credit note,
 * {@see Invoice::isCancelled()} answers, the scopes {@see Invoice::scopeCancelled()} and
 * {@see Invoice::scopeNotCancelled()} filter, and {@see Invoice::CANCELLED_SQL} is the same rule as SQL text for
 * correlated fragments. Under the Partner scope the relation finds the own client's issued credit note, so a
 * Partner sees the cancelled state through a row it may read.
 *
 * The freeze criterion is the lock, not the state (IN-11; D-01, D-12, D-08). An issued invoice or proforma stays
 * open until a successfully sent document e-mail locks it ({@see Invoice::isLocked()}); a credit note is locked at
 * its own issue. A locked row accepts no write at all, a timestamp-only one included (the database refuses it with
 * KP001 for every writer), and an open issued row accepts every write except the number, variable symbol, kind,
 * client, state, VAT mode, appearance snapshot, document links, storno marker and issue time, which never change
 * once the document is issued. There is no PDF relation: the link to the stored PDF is the Admin-only
 * `invoice_admin.pdf_document_id`.
 *
 * Only the printed note is fillable. The client, kind, state, number, totals, snapshots and links are set with
 * `forceFill` by the Actions, so a request can never write them by mass assignment. The history is an allowlist:
 * the printed note and the snapshots are never logged.
 *
 * @property string $id
 * @property string $client_id
 * @property InvoiceKind $kind
 * @property InvoiceState $state
 * @property string|null $number
 * @property string|null $variable_symbol
 * @property string $currency
 * @property InvoiceLanguage $language
 * @property InvoiceLockState $lock_state
 * @property VatMode $vat_mode
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $taxable_supply_date
 * @property CarbonImmutable|null $due_date
 * @property int $subtotal_minor
 * @property int $vat_minor
 * @property int $total_minor
 * @property string|null $note
 * @property array<string, mixed>|null $supplier_snapshot
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $bank_snapshot
 * @property array<string, mixed>|null $appearance_snapshot
 * @property string|null $proforma_id
 * @property string|null $credited_invoice_id
 * @property bool $cancels_invoice
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property bool|null $storno_credit_note_exists loaded by `withExists('stornoCreditNote')`
 */
#[Fillable(['note'])]
#[LoggedAttributes([
    'client_id',
    'kind',
    'state',
    'number',
    'variable_symbol',
    'currency',
    'language',
    'vat_mode',
    'issue_date',
    'taxable_supply_date',
    'due_date',
    'subtotal_minor',
    'vat_minor',
    'total_minor',
    'proforma_id',
    'credited_invoice_id',
    'cancels_invoice',
    'issued_at',
])]
final class Invoice extends KokpitModel implements PartnerIsolated
{
    use IsolatesPartners, LogsAllowlistedActivity;

    /**
     * The cancelled rule as SQL text over the outer table `invoices`, for correlated fragments that cannot
     * use the relation (the lists of plan 10-16). It is the same rule as {@see Invoice::stornoCreditNote()}: an
     * issued credit note with `cancels_invoice` names the invoice. The inner alias keeps it usable inside a
     * subquery that itself reads `invoices`.
     */
    public const string CANCELLED_SQL = "EXISTS (SELECT 1 FROM invoices AS storno_notes WHERE storno_notes.credited_invoice_id = invoices.id AND storno_notes.cancels_invoice AND storno_notes.state = 'issued')";

    /**
     * Issued, of the own client, of the three kinds, and the client is not archived.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $table = $this->getTable();

        $query
            ->where("{$table}.client_id", $clientId)
            ->where("{$table}.state", InvoiceState::Issued->value)
            ->whereIn("{$table}.kind", array_column(InvoiceKind::cases(), 'value'))
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')
                ->from('clients')
                ->whereColumn('clients.id', "{$table}.client_id")
                ->whereNull('clients.deleted_at'));
    }

    /**
     * Only the cancelled invoices (an issued storno credit note names them).
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeCancelled(Builder $query): void
    {
        $query->whereHas('stornoCreditNote');
    }

    /**
     * Only the invoices no issued storno credit note names.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeNotCancelled(Builder $query): void
    {
        $query->whereDoesntHave('stornoCreditNote');
    }

    public function isDraft(): bool
    {
        return $this->state === InvoiceState::Draft;
    }

    public function isIssued(): bool
    {
        return $this->state === InvoiceState::Issued;
    }

    /**
     * Whether the document is locked: sent by e-mail, or a credit note. A draft is never locked.
     */
    public function isLocked(): bool
    {
        return $this->lock_state === InvoiceLockState::Locked;
    }

    /**
     * Whether an issued storno credit note names this invoice. A list loads the flag once with
     * `withExists('stornoCreditNote')`, so the rows do not query one by one; a loaded relation is used as well.
     */
    public function isCancelled(): bool
    {
        if (array_key_exists('storno_credit_note_exists', $this->attributes)) {
            return (bool) $this->attributes['storno_credit_note_exists'];
        }

        if ($this->relationLoaded('stornoCreditNote')) {
            return $this->getRelation('stornoCreditNote') !== null;
        }

        return $this->stornoCreditNote()->exists();
    }

    public function subtotal(): Money
    {
        return Money::ofMinor($this->subtotal_minor, $this->currency);
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * The printed lines, in order.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /**
     * The Admin-only half of the document: internal note, CNB snapshot, CZK total and the stored PDF link. Null for
     * a Partner, whose query of the Admin-only model returns nothing (IN-09).
     *
     * @return HasOne<InvoiceAdmin, $this>
     */
    public function admin(): HasOne
    {
        return $this->hasOne(InvoiceAdmin::class);
    }

    /**
     * The payments of the document, oldest payment date first (IN-05). Empty for a Partner.
     *
     * @return HasMany<InvoicePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * The e-mails sent for the document, latest first (D-22). Empty for a Partner.
     *
     * @return HasMany<InvoiceEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(InvoiceEmail::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The tracked time reserved for or billed on the document (D-03), by `time_entries.invoice_id`. Tracked time is
     * Admin-only, so the relation is empty for a Partner.
     *
     * @return HasMany<TimeEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'invoice_id')->orderBy('started_at')->orderBy('id');
    }

    /**
     * The files that hang on the document: its stored PDF and any file the Admin added (DO-01, D-21). Never shared with
     * a Partner, a document is finance data; a Partner is served the PDF through its own signed route (plan 10-38).
     *
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    /**
     * The proforma this invoice was made from, if any.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(self::class, 'proforma_id');
    }

    /**
     * The invoice made from this proforma, if any (one per proforma).
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoiceFromProforma(): HasOne
    {
        return $this->hasOne(self::class, 'proforma_id');
    }

    /**
     * The invoice a credit note credits.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credited_invoice_id');
    }

    /**
     * Every credit note that names this invoice, partial and storno, oldest first.
     *
     * @return HasMany<Invoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credited_invoice_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * The issued credit note that cancels this invoice: the one marker of the cancelled state (R-04-5). There is
     * at most one, the database allows one storno per invoice.
     *
     * @return HasOne<Invoice, $this>
     */
    public function stornoCreditNote(): HasOne
    {
        // Unqualified columns on purpose: a self-relation existence query aliases the table, and a column
        // qualified with `invoices` would read the outer row instead.
        return $this->hasOne(self::class, 'credited_invoice_id')
            ->where('cancels_invoice', true)
            ->where('state', InvoiceState::Issued->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'state' => InvoiceState::class,
            'lock_state' => InvoiceLockState::class,
            'language' => InvoiceLanguage::class,
            'vat_mode' => VatMode::class,
            'issue_date' => 'immutable_date',
            'taxable_supply_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'subtotal_minor' => 'integer',
            'vat_minor' => 'integer',
            'total_minor' => 'integer',
            'supplier_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'bank_snapshot' => 'array',
            'appearance_snapshot' => 'array',
            'cancels_invoice' => 'boolean',
            'issued_at' => 'immutable_datetime',
        ];
    }
}
