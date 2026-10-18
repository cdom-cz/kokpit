<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The record of one payment or refund event that Stripe reported (PA-06, PA-07, PA-08; D-11 to D-14, D-17). Admin-only:
 * a Partner reads no record, not even of the own client's document, because the row holds Stripe references and the
 * resolution note.
 *
 * The row never depends on the webhook row (D-17) and is never deleted. Its type, reason, amounts, currency, Stripe
 * references, document, link and receipt time never change; the resolution moves once from `none` to assigned, noted
 * or returned and is final. The unique Stripe references are the idempotency keys of the phase: they outlive both the
 * webhook row and a payment deleted through `DeletePayment` (the payment key is set to null by the database), so a
 * redelivery or a replay of the same event, session or payment intent records nothing again (D-14).
 *
 * The history is an allowlist: type, reason, resolution and its time, amounts, currency, document links and the
 * receipt time, never a Stripe reference or the resolution note. A record is created by {@see CardPayment::recordOnce()}
 * (a plain insert, so its creation raises no model event; the row is its own history), changed only through model
 * saves, which write the allowlisted `updated` activity.
 *
 * @property string $id
 * @property CardPaymentType $type
 * @property CardPaymentReason|null $reason
 * @property CardPaymentResolution $resolution
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolved_by
 * @property string|null $resolution_note
 * @property string|null $assigned_invoice_id
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $offered_amount_minor
 * @property string|null $offered_currency
 * @property string $stripe_event_id
 * @property string|null $stripe_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $stripe_refund_id
 * @property string|null $stripe_link_id
 * @property string|null $invoice_id
 * @property string|null $payment_link_id
 * @property string|null $invoice_payment_id
 * @property string|null $return_transaction_id
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[LoggedAttributes(['type', 'reason', 'resolution', 'resolved_at', 'amount_minor', 'currency', 'invoice_id', 'assigned_invoice_id', 'invoice_payment_id', 'return_transaction_id', 'received_at'])]
final class CardPayment extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    /**
     * The columns a caller of {@see CardPayment::recordOnce()} may set. The resolution and everything that belongs to
     * it start empty; the later moves are saved on the model.
     */
    private const array RECORDABLE = [
        'type', 'reason', 'amount_minor', 'currency', 'offered_amount_minor', 'offered_currency',
        'stripe_event_id', 'stripe_session_id', 'stripe_payment_intent_id', 'stripe_refund_id', 'stripe_link_id',
        'invoice_id', 'payment_link_id', 'invoice_payment_id', 'received_at',
    ];

    protected $table = 'card_payments';

    /**
     * Only the note of a resolution is mass assignable; every other column is written by the services with
     * `forceFill`.
     *
     * @var list<string>
     */
    protected $fillable = ['resolution_note'];

    /**
     * Stores the record of a Stripe event once (PA-07, D-12, D-14) and returns it, or null when the event, its checkout
     * session, its payment intent (for a payment) or its refund is already recorded.
     *
     * The insert is `INSERT ... ON CONFLICT DO NOTHING` against the unique Stripe references: a repeat inserts nothing
     * and raises nothing, so it cannot abort the enclosing PostgreSQL transaction the way a caught unique violation
     * would. A row whose payment was deleted still holds its references, so a replay stays a no-op. The record is read
     * back as system, because the model denies Partners.
     *
     * @param  array<string, mixed>  $attributes  the recordable columns; enums and dates are accepted as objects
     *
     * @throws LogicException when called outside a database transaction
     * @throws InvalidArgumentException for a column that is not recordable (the resolution is never set here)
     */
    public static function recordOnce(array $attributes): ?self
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CardPayment::recordOnce() must run inside the caller\'s transaction.');
        }

        $unknown = array_diff(array_keys($attributes), self::RECORDABLE);

        if ($unknown !== []) {
            throw new InvalidArgumentException('A card payment record cannot be created with: '.implode(', ', $unknown));
        }

        $row = [];

        foreach ($attributes as $column => $value) {
            $row[$column] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:sP'),
                default => $value,
            };
        }

        $id = (string) Str::uuid7();
        $now = now();

        $inserted = self::query()->insertOrIgnore([
            'id' => $id,
            ...$row,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            return null;
        }

        return app(PartnerContext::class)->runAsSystem(static fn (): self => self::query()->whereKey($id)->firstOrFail());
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /**
     * The amount the link offered, for the "Nabízeno" line; null when the record has none.
     */
    public function offered(): ?Money
    {
        if ($this->offered_amount_minor === null || $this->offered_currency === null) {
            return null;
        }

        return Money::ofMinor($this->offered_amount_minor, $this->offered_currency);
    }

    /**
     * Waits for the Admin: not a matched payment and not yet resolved.
     */
    public function isUnresolved(): bool
    {
        return $this->type !== CardPaymentType::Matched && $this->resolution === CardPaymentResolution::None;
    }

    /**
     * Derived, never stored: a booked payment, one whose payment was deleted (a matched record, or a record assigned
     * to a document, that lost its payment), one that waits for the Admin, or a resolved one.
     */
    public function status(): CardPaymentStatus
    {
        $placed = $this->type === CardPaymentType::Matched || $this->resolution === CardPaymentResolution::Assigned;

        if ($placed && $this->invoice_payment_id === null) {
            return CardPaymentStatus::PaymentDeleted;
        }

        if ($this->type === CardPaymentType::Matched) {
            return CardPaymentStatus::Booked;
        }

        return $this->isUnresolved() ? CardPaymentStatus::Unresolved : CardPaymentStatus::Resolved;
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function assignedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'assigned_invoice_id');
    }

    /**
     * @return BelongsTo<InvoicePayment, $this>
     */
    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class);
    }

    /**
     * @return BelongsTo<PaymentLink, $this>
     */
    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function returnTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'return_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CardPaymentType::class,
            'reason' => CardPaymentReason::class,
            'resolution' => CardPaymentResolution::class,
            'resolved_at' => 'immutable_datetime',
            'amount_minor' => 'integer',
            'offered_amount_minor' => 'integer',
            'received_at' => 'immutable_datetime',
        ];
    }
}
