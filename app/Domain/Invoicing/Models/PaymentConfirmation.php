<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The confirmation e-mail of one payment credited to an invoice (D-05): at most one row per payment, enforced by the
 * unique `invoice_payment_id` of the database, removed with its payment by the cascading foreign key (A-13-6). The
 * row keeps the recipient, the language and the amount still owed after the payment, and the state of the job that
 * sends it.
 *
 * Admin-only: a Partner reads no row, not even for a payment of the own client (P13-11). The relations write nothing
 * to their parents: there is no `$touches`, because a locked invoice refuses even an `updated_at` write.
 *
 * Nothing is fillable: the trigger and the job set every column with `forceFill`. The model keeps no activity history,
 * the row is itself the log.
 *
 * @property string $id
 * @property string $invoice_payment_id
 * @property string $invoice_id
 * @property string|null $recipient
 * @property string $language
 * @property int $remaining_minor
 * @property PaymentConfirmationState $state
 * @property int $attempts
 * @property CarbonImmutable|null $sent_at
 * @property string|null $failure_code
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentConfirmation extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    protected $table = 'payment_confirmations';

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<InvoicePayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => PaymentConfirmationState::class,
            'attempts' => 'integer',
            'remaining_minor' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
