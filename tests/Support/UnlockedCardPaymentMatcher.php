<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Payments\Actions\RecordCardPayment;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Test-only mutation for the card payment concurrency harness; never used by application code.
 *
 * It is {@see RecordCardPayment} without the document lock: the document and the remaining
 * amount are read with no lock, a barrier callback runs so that the worker can wait for the start of the race, and then
 * the matcher waits until another worker has committed a matched record for the document and only then claims its own
 * matched record and books its payment through `RecordPayment::recordLocked()` with the unlocked model. The round ends
 * with two matched records and two incomes, which the harness must see. If it does not, a clean run of the locked
 * scenarios proves nothing about the document lock.
 */
final readonly class UnlockedCardPaymentMatcher
{
    public function __construct(
        private RecordPayment $payments,
        private InvoiceBalances $balances,
    ) {}

    /**
     * @param  Closure(): void  $afterUnlockedRead  runs after the unlocked read and before anything is written
     */
    public function handle(StripeEvent $event, Closure $afterUnlockedRead): CardPayment
    {
        if ($event->id === null || $event->sessionId === null || $event->amountMinor === null || $event->currency === null || $event->paymentLinkRow === null) {
            throw new LogicException('The unlocked matcher expects a complete paid session of a Kokpit link.');
        }

        $link = PaymentLink::query()->whereKey($event->paymentLinkRow)->firstOrFail();

        // The only difference to the real handler: no lock on the document, so the remaining amount is judged on a
        // read that another worker's payment can still overtake.
        $document = Invoice::query()->whereKey($link->invoice_id)->firstOrFail();
        $remaining = $this->balances->for([(string) $document->getKey()])[(string) $document->getKey()]['remaining'] ?? 0;

        if ($remaining !== $event->amountMinor || $remaining !== $link->amount_minor) {
            throw new LogicException('The unlocked matcher expects the document to be unpaid at its unlocked read.');
        }

        $afterUnlockedRead();

        $deadline = microtime(true) + 60.0;

        while (! CardPayment::query()->where('invoice_id', $document->getKey())->where('type', CardPaymentType::Matched->value)->exists()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The unlocked matcher waited in vain for the other worker to commit.');
            }

            usleep(2000);
        }

        return DB::transaction(function () use ($event, $link, $document): CardPayment {
            $record = CardPayment::recordOnce([
                'type' => CardPaymentType::Matched,
                'amount_minor' => $event->amountMinor,
                'currency' => $event->currency,
                'offered_amount_minor' => $link->amount_minor,
                'offered_currency' => $link->currency,
                'stripe_event_id' => $event->id,
                'stripe_session_id' => $event->sessionId,
                'stripe_payment_intent_id' => $event->paymentIntentId,
                'stripe_link_id' => $link->stripe_link_id,
                'invoice_id' => $document->getKey(),
                'payment_link_id' => $link->getKey(),
                'received_at' => CarbonImmutable::now(),
            ]) ?? throw new RuntimeException('The unlocked matcher found its event already recorded.');

            $paidOn = $event->created === null
                ? IssueContext::today()
                : $event->created->setTimezone(FilamentTimezone::get())->format('Y-m-d');

            $payment = $this->payments->recordLocked(
                $document,
                new PaymentFacts(Money::ofMinor((int) $event->amountMinor, $document->currency), $paidOn, PaymentMethod::Card, $paidOn, null, null),
                null,
            );

            $record->forceFill(['invoice_payment_id' => $payment->getKey()])->save();

            return $record;
        });
    }
}
