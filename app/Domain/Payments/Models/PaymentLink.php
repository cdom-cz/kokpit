<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One Stripe payment link of an issued invoice or proforma (PA-01, PA-04; D-05 to D-08). Admin-only: a Partner
 * reads no link row, not even of the own client's document, because the row holds the Stripe references and the
 * state that decides whether a client is sent to Stripe.
 *
 * The state machine lives in the database ({@see PaymentLinkState}): the row is opened pending with the amount the
 * link offers, becomes active with its Stripe references or failed with a failure class, and ends deactivated with
 * a reason. At most one live link exists per document; the document, currency, amount and creator never change and
 * the row is never deleted. The services of the link job and the reconcile step write the later moves with
 * `forceFill`; nothing is fillable.
 *
 * The history is an allowlist: state, reason, amount, currency and dates, never the Stripe id or URL.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $currency
 * @property int $amount_minor
 * @property PaymentLinkState $state
 * @property PaymentLinkDeactivation|null $deactivation_reason
 * @property string|null $failure_class
 * @property string|null $stripe_link_id
 * @property string|null $stripe_url
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable|null $remote_deactivated_at
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[LoggedAttributes(['invoice_id', 'currency', 'amount_minor', 'state', 'deactivation_reason', 'failure_class', 'activated_at', 'deactivated_at', 'remote_deactivated_at'])]
final class PaymentLink extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    protected $table = 'payment_links';

    /**
     * Opens a pending link for the invoice, which the caller has locked inside the transaction it is in.
     *
     * The database refuses a document that is not issued, is a credit note or has the card switch off, a second live
     * link, and a currency other than the one of the document.
     *
     * @throws LogicException when called outside a database transaction
     */
    public static function open(Invoice $lockedInvoice, int $amountMinor, ?User $by): self
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PaymentLink::open() must run inside the caller\'s transaction, with the invoice locked.');
        }

        $link = new self;
        $link->forceFill([
            'invoice_id' => $lockedInvoice->getKey(),
            'currency' => $lockedInvoice->currency,
            'amount_minor' => $amountMinor,
            'state' => PaymentLinkState::Pending,
            'created_by' => $by?->getKey(),
        ])->save();

        return $link;
    }

    /**
     * The link to show for a document: its live one, otherwise the newest.
     */
    public static function latestFor(string $invoiceId): ?self
    {
        return self::query()
            ->where('invoice_id', $invoiceId)
            ->orderByRaw("CASE WHEN state IN ('pending', 'active') THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The amount the link offers, in the currency of the document.
     */
    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function isLive(): bool
    {
        return $this->state->isLive();
    }

    /**
     * Pending and active links.
     *
     * @param  Builder<PaymentLink>  $query
     * @return Builder<PaymentLink>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('state', [PaymentLinkState::Pending->value, PaymentLinkState::Active->value]);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'state' => PaymentLinkState::class,
            'deactivation_reason' => PaymentLinkDeactivation::class,
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'remote_deactivated_at' => 'immutable_datetime',
        ];
    }
}
