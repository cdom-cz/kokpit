<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One payment of an issued invoice or proforma (IN-05, D-17). Admin-only: a Partner reads no payment, not even of
 * the own client's issued invoice (IN-09, R-08-1).
 *
 * A payment is recorded in the currency of its document and never updated afterwards (D-19); the database refuses
 * every update and allows a deletion only inside the sanctioned deletion of plan 10-41. The model therefore only
 * reads: the Actions create it with `forceFill`, and only the free-text note is fillable. A payment that was
 * carried over from a proforma names its source in `carried_from_payment_id`.
 *
 * Recording a payment writes nothing to the invoice: there is no `$touches`, an issued `invoices` row refuses even
 * an `updated_at` write (R-04-6).
 *
 * The history is an allowlist (research Open Question 12): the invoice, amount, currency, date, method and the
 * carried-from link. The free-text note is never logged (T-10-22).
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $currency
 * @property int $amount_minor
 * @property CarbonImmutable $paid_on
 * @property PaymentMethod $method
 * @property string|null $note
 * @property string|null $carried_from_payment_id
 * @property string $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['note'])]
#[LoggedAttributes(['invoice_id', 'amount_minor', 'currency', 'paid_on', 'method', 'carried_from_payment_id'])]
final class InvoicePayment extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    protected $table = 'invoice_payments';

    /**
     * The paid amount, in the currency of the document.
     */
    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The proforma payment this one was carried over from, if any.
     *
     * @return BelongsTo<InvoicePayment, $this>
     */
    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_payment_id');
    }

    /**
     * The income transaction the payment created in the finance module (FI-03).
     *
     * @return HasOne<Transaction, $this>
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'invoice_payment_id');
    }

    /**
     * The confirmation e-mail of the payment (Phase 13, R-13-08-1), read only: a carried copy and a payment recorded
     * before the confirmation existed have none. The payment row itself stays immutable.
     *
     * @return HasOne<PaymentConfirmation, $this>
     */
    public function confirmation(): HasOne
    {
        return $this->hasOne(PaymentConfirmation::class, 'invoice_payment_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_on' => 'immutable_date',
            'method' => PaymentMethod::class,
        ];
    }
}
