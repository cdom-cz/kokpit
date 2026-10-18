<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeEvents;

/*
 * The record of every card payment and refund event that Stripe reports (PA-06, PA-07, PA-08; D-11 to D-14, D-17).
 * Every name and amount is fictional; Stripe references are assembled at runtime and never written down in a test.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    Queue::fake();
});

/**
 * A fresh set of Stripe references for one event, assembled at runtime.
 *
 * @return array{stripe_event_id: string, stripe_session_id: string, stripe_payment_intent_id: string, stripe_link_id: string}
 */
function cardRefs(): array
{
    return [
        'stripe_event_id' => StripeEvents::token('evt'),
        'stripe_session_id' => StripeEvents::token('cs', 'test'),
        'stripe_payment_intent_id' => StripeEvents::token('pi'),
        'stripe_link_id' => StripeEvents::token('plink'),
    ];
}

/**
 * Records the card payment of a whole document as the queue job will: as system, in one transaction, the document
 * locked, the payment and its income through the one write path, then the matched record.
 *
 * @param  array<string, mixed>  $overrides  columns of the record replacing the matched defaults
 * @return array{0: InvoicePayment, 1: CardPayment|null, 2: array<string, mixed>}
 */
function recordMatchedCardPayment(Invoice $invoice, array $overrides = []): array
{
    $attributes = [
        'type' => CardPaymentType::Matched,
        'amount_minor' => $invoice->total_minor,
        'currency' => $invoice->currency,
        'invoice_id' => $invoice->id,
        'received_at' => now(),
        ...cardRefs(),
        ...$overrides,
    ];

    return app(PartnerContext::class)->runAsSystem(static fn (): array => DB::transaction(static function () use ($invoice, $attributes): array {
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        $payment = app(RecordPayment::class)->recordLocked($locked, new PaymentFacts(
            Money::ofMinor($locked->total_minor, $locked->currency),
            IssueContext::today(),
            PaymentMethod::Card,
            IssueContext::today(),
            null,
            null,
        ), null);

        $record = CardPayment::recordOnce([...$attributes, 'invoice_payment_id' => $payment->id]);

        return [$payment, $record, $attributes];
    }));
}

/**
 * Calls recordOnce() as system, in its own transaction.
 *
 * @param  array<string, mixed>  $attributes
 */
function recordOnceAsSystem(array $attributes): ?CardPayment
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?CardPayment => DB::transaction(static fn (): ?CardPayment => CardPayment::recordOnce($attributes)));
}

describe('the tracer', function (): void {
    it('records a matched card payment once, survives the deletion of its payment and can never be booked again by a retry', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        [$payment, $record, $attributes] = recordMatchedCardPayment($invoice);

        expect($record)->toBeInstanceOf(CardPayment::class)
            ->and($record->type)->toBe(CardPaymentType::Matched)
            ->and($record->resolution)->toBe(CardPaymentResolution::None)
            ->and($record->reason)->toBeNull()
            ->and($record->invoice_id)->toBe($invoice->id)
            ->and($record->invoice_payment_id)->toBe($payment->id)
            ->and($record->stripe_event_id)->toBe($attributes['stripe_event_id'])
            ->and($record->amount()->minor)->toBe($invoice->total_minor)
            ->and($record->status())->toBe(CardPaymentStatus::Booked)
            ->and($record->isUnresolved())->toBeFalse();

        $this->actingAs(Canary::admin());
        app(DeletePayment::class)->handle(Canary::admin(), $payment);

        $kept = CardPayment::query()->findOrFail($record->id);

        expect(InvoicePayment::query()->find($payment->id))->toBeNull()
            ->and($kept->invoice_payment_id)->toBeNull()
            ->and($kept->stripe_event_id)->toBe($attributes['stripe_event_id'])
            ->and($kept->stripe_session_id)->toBe($attributes['stripe_session_id'])
            ->and($kept->stripe_payment_intent_id)->toBe($attributes['stripe_payment_intent_id'])
            ->and($kept->stripe_link_id)->toBe($attributes['stripe_link_id'])
            ->and($kept->status())->toBe(CardPaymentStatus::PaymentDeleted);

        $base = [
            'type' => CardPaymentType::Matched,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'invoice_id' => $invoice->id,
            'received_at' => now(),
        ];

        // The same event again (a redelivery), a new event of the same session (a replay), a new event and session of
        // the same payment intent: each is refused by a reserved reference and stores nothing.
        $sameEvent = recordOnceAsSystem([...$base, ...cardRefs(), 'stripe_event_id' => $attributes['stripe_event_id']]);
        $sameSession = recordOnceAsSystem([...$base, ...cardRefs(), 'stripe_session_id' => $attributes['stripe_session_id']]);
        $sameIntent = recordOnceAsSystem([...$base, ...cardRefs(), 'stripe_payment_intent_id' => $attributes['stripe_payment_intent_id']]);

        expect($sameEvent)->toBeNull()
            ->and($sameSession)->toBeNull()
            ->and($sameIntent)->toBeNull()
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => CardPayment::query()->count()))->toBe(1)
            ->and(InvoicePayment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
    });

    it('shows a Partner of the same client no card payment and refuses the policy', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);
        [, $record] = recordMatchedCardPayment($invoice);

        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        expect(CardPayment::query()->count())->toBe(0)
            ->and(CardPayment::query()->find($record?->id))->toBeNull()
            ->and($partner->can('view', $record))->toBeFalse()
            ->and($partner->can('viewAny', CardPayment::class))->toBeFalse();
    });

    it('refuses to record outside a transaction', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        // RefreshDatabase wraps the test in a transaction; leave it to see the guard.
        $level = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        try {
            expect(fn () => CardPayment::recordOnce([
                'type' => CardPaymentType::Matched,
                'amount_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'invoice_id' => $invoice->id,
                'received_at' => now(),
                ...cardRefs(),
            ]))->toThrow(LogicException::class);
        } finally {
            while (DB::transactionLevel() < $level) {
                DB::beginTransaction();
            }
        }
    });

    it('carries a Czech label for every type, reason, resolution and status', function (): void {
        foreach ([...CardPaymentType::cases(), ...CardPaymentReason::cases(), ...CardPaymentResolution::cases(), ...CardPaymentStatus::cases()] as $case) {
            expect($case->getLabel())->not->toStartWith('enums.');
        }

        expect(CardPaymentStatus::PaymentDeleted->getLabel())->toBe('Smazaná platba')
            ->and(CardPaymentStatus::Booked->getLabel())->toBe('Zapsáno k dokladu')
            ->and(CardPaymentReason::Undetermined->getLabel())->toBe('Doklad nelze určit')
            ->and(CardPaymentType::Refund->getLabel())->toBe('Vrácení');
    });
});
