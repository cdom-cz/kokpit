<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Card payment records for the overview tests (Phase 11): one writer per kind, each writing as system through
 * `CardPayment::recordOnce()` like the processing job will. Every Stripe reference is assembled at runtime from
 * fragments, so no test file holds a complete value; every amount and note is fictional.
 *
 * `$overrides` replace any recordable column of the record; the document is the optional first argument.
 */
final class CardPaymentFixtures
{
    /**
     * A payment matched to its document and booked: the payment and its income are written through the one write path
     * first, then the record that points at them.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function matched(Invoice $invoice, array $overrides = []): CardPayment
    {
        return self::write(static function () use ($invoice, $overrides): ?CardPayment {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $payment = app(RecordPayment::class)->recordLocked($locked, new PaymentFacts(
                Money::ofMinor($locked->total_minor, $locked->currency),
                IssueContext::today(),
                PaymentMethod::Card,
                IssueContext::today(),
                null,
                null,
            ), null);

            return CardPayment::recordOnce([
                ...self::base($invoice->total_minor, $invoice->currency),
                'type' => CardPaymentType::Matched,
                'invoice_id' => $invoice->id,
                'invoice_payment_id' => $payment->id,
                ...$overrides,
            ]);
        });
    }

    /**
     * A matched record whose payment was deleted from the document afterwards (the payment key is null, every Stripe
     * reference stays).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function matchedThenDeleted(Invoice $invoice, array $overrides = []): CardPayment
    {
        return self::record([
            ...self::base($invoice->total_minor, $invoice->currency),
            'type' => CardPaymentType::Matched,
            'invoice_id' => $invoice->id,
            ...$overrides,
        ]);
    }

    /**
     * A second payment of a document that was already paid.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function duplicate(Invoice $invoice, array $overrides = []): CardPayment
    {
        return self::record([
            ...self::base($invoice->total_minor, $invoice->currency),
            'type' => CardPaymentType::Duplicate,
            'reason' => CardPaymentReason::AlreadyPaid,
            'invoice_id' => $invoice->id,
            ...$overrides,
        ]);
    }

    /**
     * A payment that could not be matched. With a document it paid another amount than the link offered; without one
     * the document is undetermined. A test of another reason overrides `reason` and the columns it needs.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function unmatched(?Invoice $invoice = null, array $overrides = []): CardPayment
    {
        if ($invoice === null) {
            return self::record([
                ...self::base(5000, 'CZK'),
                'type' => CardPaymentType::Unmatched,
                'reason' => CardPaymentReason::Undetermined,
                ...$overrides,
            ]);
        }

        return self::record([
            ...self::base($invoice->total_minor + 100000, $invoice->currency),
            'type' => CardPaymentType::Unmatched,
            'reason' => CardPaymentReason::Amount,
            'offered_amount_minor' => $invoice->total_minor,
            'offered_currency' => $invoice->currency,
            'invoice_id' => $invoice->id,
            ...$overrides,
        ]);
    }

    /**
     * A refund event; it carries a refund reference and no reason.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function refund(?Invoice $invoice = null, array $overrides = []): CardPayment
    {
        return self::record([
            ...self::base($invoice === null ? 3000 : intdiv($invoice->total_minor, 2), $invoice?->currency ?? 'CZK'),
            'type' => CardPaymentType::Refund,
            'stripe_refund_id' => StripeEvents::token('re'),
            'invoice_id' => $invoice?->id,
            ...$overrides,
        ]);
    }

    /**
     * An unmatched payment the Admin closed with a note.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function resolved(?Invoice $invoice = null, array $overrides = [], string $note = 'Returned in the Stripe dashboard (fictional).'): CardPayment
    {
        $record = self::unmatched($invoice, $overrides);

        app(PartnerContext::class)->runAsSystem(static function () use ($record, $note): void {
            $record->forceFill([
                'resolution' => CardPaymentResolution::Noted,
                'resolved_at' => CarbonImmutable::now(),
                'resolved_by' => Canary::admin()->getKey(),
                'resolution_note' => $note,
            ])->save();
        });

        return self::reload($record);
    }

    /**
     * The columns every record shares: a fresh set of Stripe references and the receipt time.
     *
     * @return array<string, mixed>
     */
    private static function base(int $amountMinor, string $currency): array
    {
        return [
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'stripe_event_id' => StripeEvents::token('evt'),
            'stripe_session_id' => StripeEvents::token('cs', 'test'),
            'stripe_payment_intent_id' => StripeEvents::token('pi'),
            'stripe_link_id' => StripeEvents::token('plink'),
            'received_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function record(array $attributes): CardPayment
    {
        return self::write(static fn (): ?CardPayment => CardPayment::recordOnce($attributes));
    }

    /**
     * @param  callable(): ?CardPayment  $callback
     */
    private static function write(callable $callback): CardPayment
    {
        $record = app(PartnerContext::class)->runAsSystem(static fn (): ?CardPayment => DB::transaction($callback));

        return $record ?? throw new LogicException('The fixture reused a Stripe reference.');
    }

    private static function reload(CardPayment $record): CardPayment
    {
        return app(PartnerContext::class)->runAsSystem(static fn (): CardPayment => CardPayment::query()->findOrFail($record->id));
    }
}
