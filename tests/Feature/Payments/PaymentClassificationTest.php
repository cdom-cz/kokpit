<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Actions\RecordCardPayment;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeEvents;
use Tests\Support\StripeWebhookSigner;

/*
 * How a paid Stripe checkout session is classified and what a repeat does (PA-06, PA-07, PA-08; D-10 to D-14,
 * research Pitfall 11, Open Questions 1 and 6). Every name, amount and Stripe reference is fictional or assembled at
 * runtime.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->admin = Canary::admin();
});

/**
 * An issued card-on invoice of 12 500,00 Kč and its link.
 *
 * @return array{0: Invoice, 1: PaymentLink}
 */
function clsInvoice(): array
{
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

    return [$invoice, clsLink($invoice)];
}

/**
 * The link of the document, read as system.
 */
function clsLink(Invoice $invoice): PaymentLink
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->firstOrFail());
}

/**
 * Every card payment record, oldest first, read as system.
 *
 * @return list<CardPayment>
 */
function clsRecords(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => CardPayment::query()->orderBy('created_at')->orderBy('id')->get()->all());
}

/**
 * The payments of the document, read as system.
 *
 * @return list<InvoicePayment>
 */
function clsPayments(Invoice $invoice): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => InvoicePayment::query()->where('invoice_id', $invoice->id)->get()->all());
}

/**
 * The incomes booked for the document, read as system.
 *
 * @return list<Transaction>
 */
function clsIncomes(Invoice $invoice): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => Transaction::query()->where('invoice_id', $invoice->id)->get()->all());
}

/**
 * What is still owed on the document.
 */
function clsRemaining(Invoice $invoice): ?int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?int => app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining']);
}

/**
 * The invoices row as the database holds it.
 */
function clsRow(Invoice $invoice): ?object
{
    return DB::table('invoices')->where('id', $invoice->id)->first();
}

/**
 * Runs the handler for an event exactly as the job does: as system, in a transaction.
 */
function clsHandle(array $event): ?CardPayment
{
    return app(PartnerContext::class)->runAsSystem(static fn (): ?CardPayment => DB::transaction(static fn (): ?CardPayment => app(RecordCardPayment::class)->handle(StripeEvent::fromPayload($event))));
}

// --- Task 2: repeats and duplicates -----------------------------------------------------------------------------

it('records the same signed event delivered twice once', function (): void {
    [$invoice, $link] = clsInvoice();
    $event = StripeEvents::checkoutFor($link);

    StripeWebhookSigner::post($this, $event)->assertOk();
    StripeWebhookSigner::post($this, $event)->assertOk();

    expect(clsRecords())->toHaveCount(1)
        ->and(clsPayments($invoice))->toHaveCount(1)
        ->and(clsIncomes($invoice))->toHaveCount(1);
});

it('ends the handler with nothing written when the event is handed to it again', function (): void {
    [$invoice, $link] = clsInvoice();
    $event = StripeEvents::checkoutFor($link);

    StripeWebhookSigner::post($this, $event)->assertOk();

    expect(clsHandle($event))->toBeNull()
        ->and(clsRecords())->toHaveCount(1)
        ->and(clsPayments($invoice))->toHaveCount(1);
});

it('ignores the async event of a session that is already recorded', function (): void {
    [$invoice, $link] = clsInvoice();
    $first = StripeEvents::checkoutFor($link);
    $session = ['id' => $first['data']['object']['id']];

    StripeWebhookSigner::post($this, $first)->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::asyncSucceeded(StripeEvents::sessionFor($link, $session)))->assertOk();

    expect(clsRecords())->toHaveCount(1)
        ->and(clsPayments($invoice))->toHaveCount(1)
        ->and(clsIncomes($invoice))->toHaveCount(1);
});

it('ignores any event of a payment intent that is already recorded', function (): void {
    [$invoice, $link] = clsInvoice();
    $first = StripeEvents::checkoutFor($link);

    StripeWebhookSigner::post($this, $first)->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link, ['payment_intent' => $first['data']['object']['payment_intent']]))->assertOk();

    expect(clsRecords())->toHaveCount(1)
        ->and(clsPayments($invoice))->toHaveCount(1);
});

it('records a second payment of a paid invoice as a duplicate with no income and no change of the document', function (): void {
    [$invoice, $link] = clsInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $rowBefore = clsRow($invoice);
    $second = StripeEvents::checkoutFor($link);

    StripeWebhookSigner::post($this, $second)->assertOk();

    $records = clsRecords();

    expect($records)->toHaveCount(2)
        ->and($records[1]->type)->toBe(CardPaymentType::Duplicate)
        ->and($records[1]->reason)->toBe(CardPaymentReason::AlreadyPaid)
        ->and($records[1]->stripe_event_id)->toBe($second['id'])
        ->and($records[1]->stripe_session_id)->toBe($second['data']['object']['id'])
        ->and($records[1]->stripe_payment_intent_id)->toBe($second['data']['object']['payment_intent'])
        ->and($records[1]->invoice_id)->toBe($invoice->id)
        ->and($records[1]->payment_link_id)->toBe($link->id)
        ->and($records[1]->amount_minor)->toBe(1_250_000)
        ->and($records[1]->currency)->toBe('CZK')
        ->and($records[1]->offered_amount_minor)->toBe(1_250_000)
        ->and($records[1]->invoice_payment_id)->toBeNull()
        ->and($records[1]->isUnresolved())->toBeTrue()
        ->and(clsPayments($invoice))->toHaveCount(1)
        ->and(clsIncomes($invoice))->toHaveCount(1)
        ->and(clsRemaining($invoice))->toBe(0)
        ->and(clsRow($invoice))->toEqual($rowBefore);

    $call = app(PartnerContext::class)->runAsSystem(static fn (): WebhookCall => WebhookCall::query()->where('event_id', $second['id'])->firstOrFail());

    expect($call->processed_at)->not->toBeNull();
});

it('never recreates a matched payment that the Admin deleted, whatever Stripe sends again', function (): void {
    [$invoice, $link] = clsInvoice();
    $first = StripeEvents::checkoutFor($link);

    StripeWebhookSigner::post($this, $first)->assertOk();

    $payment = clsPayments($invoice)[0];
    $this->actingAs($this->admin);
    app(DeletePayment::class)->handle($this->admin, $payment);

    expect(clsPayments($invoice))->toBe([])
        ->and(clsRemaining($invoice))->toBe(1_250_000);

    // The same event again, and a new event of the same session.
    $again = clsHandle($first);
    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link, ['id' => $first['data']['object']['id']]))->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::asyncSucceeded(StripeEvents::sessionFor($link, ['id' => $first['data']['object']['id']])))->assertOk();

    $records = clsRecords();

    expect($again)->toBeNull()
        ->and($records)->toHaveCount(1)
        ->and($records[0]->invoice_payment_id)->toBeNull()
        ->and($records[0]->status())->toBe(CardPaymentStatus::PaymentDeleted)
        ->and(clsPayments($invoice))->toBe([])
        ->and(clsIncomes($invoice))->toBe([])
        ->and(clsRemaining($invoice))->toBe(1_250_000);
});

it('records nothing for a session that is not paid yet and still marks the event processed', function (): void {
    [$invoice, $link] = clsInvoice();
    $event = StripeEvents::checkoutFor($link, ['payment_status' => 'unpaid']);

    StripeWebhookSigner::post($this, $event)->assertOk();

    $call = app(PartnerContext::class)->runAsSystem(static fn (): WebhookCall => WebhookCall::query()->where('event_id', $event['id'])->firstOrFail());

    expect(clsRecords())->toBe([])
        ->and(clsPayments($invoice))->toBe([])
        ->and($call->processed_at)->not->toBeNull();
});

it('books the later async event of an unpaid session once it is paid', function (): void {
    [$invoice, $link] = clsInvoice();
    $first = StripeEvents::checkoutFor($link, ['payment_status' => 'unpaid']);
    $session = ['id' => $first['data']['object']['id']];

    StripeWebhookSigner::post($this, $first)->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::asyncSucceeded(StripeEvents::sessionFor($link, $session)))->assertOk();

    $records = clsRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->type)->toBe(CardPaymentType::Matched)
        ->and(clsPayments($invoice))->toHaveCount(1)
        ->and(clsRemaining($invoice))->toBe(0)
        ->and(clsLink($invoice)->state)->toBe(PaymentLinkState::Deactivated);
});
