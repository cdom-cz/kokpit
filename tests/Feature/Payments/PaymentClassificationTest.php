<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Actions\RecordCardPayment;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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

// --- Task 3: unmatched payments, a proforma and a foreign currency ------------------------------------------------

/**
 * The one record that exists, with the shape every unmatched record shares checked.
 */
function clsOnlyRecord(): CardPayment
{
    $records = clsRecords();

    expect($records)->toHaveCount(1);

    return $records[0];
}

it('records a payment of another amount as unmatched and leaves the document and its link alone', function (): void {
    [$invoice, $link] = clsInvoice();
    $rowBefore = clsRow($invoice);

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link, ['amount_total' => 1_200_000]))->assertOk();

    $record = clsOnlyRecord();

    expect($record->type)->toBe(CardPaymentType::Unmatched)
        ->and($record->reason)->toBe(CardPaymentReason::Amount)
        ->and($record->amount_minor)->toBe(1_200_000)
        ->and($record->offered_amount_minor)->toBe(1_250_000)
        ->and($record->offered_currency)->toBe('CZK')
        ->and($record->invoice_id)->toBe($invoice->id)
        ->and(clsPayments($invoice))->toBe([])
        ->and(clsIncomes($invoice))->toBe([])
        ->and(clsRemaining($invoice))->toBe(1_250_000)
        ->and(clsRow($invoice))->toEqual($rowBefore)
        ->and(clsLink($invoice)->state)->toBe(PaymentLinkState::Active);
});

it('records a payment in another currency as unmatched', function (): void {
    [$invoice, $link] = clsInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link, ['currency' => 'eur']))->assertOk();

    $record = clsOnlyRecord();

    expect($record->type)->toBe(CardPaymentType::Unmatched)
        ->and($record->reason)->toBe(CardPaymentReason::Currency)
        ->and($record->currency)->toBe('EUR')
        ->and($record->offered_currency)->toBe('CZK')
        ->and(clsPayments($invoice))->toBe([])
        ->and(clsIncomes($invoice))->toBe([]);
});

it('records the whole offered amount as unmatched once part of the invoice was paid by hand', function (): void {
    [$invoice, $link] = clsInvoice();
    $this->actingAs($this->admin);
    app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '5000.00', 'paid_on' => IssueContext::today()]);

    expect(clsLink($invoice)->state)->toBe(PaymentLinkState::Deactivated)
        ->and(clsRemaining($invoice))->toBe(750_000);

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $record = clsOnlyRecord();

    expect($record->type)->toBe(CardPaymentType::Unmatched)
        ->and($record->reason)->toBe(CardPaymentReason::Amount)
        ->and($record->amount_minor)->toBe(1_250_000)
        ->and(clsPayments($invoice))->toHaveCount(1)
        ->and(clsRemaining($invoice))->toBe(750_000);
});

it('records a payment for a cancelled invoice as unmatched cancelled', function (): void {
    [$invoice, $link] = clsInvoice();
    $this->actingAs($this->admin);
    app(IssueCreditNote::class)->storno($this->admin, $invoice);

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $record = clsOnlyRecord();

    expect($record->type)->toBe(CardPaymentType::Unmatched)
        ->and($record->reason)->toBe(CardPaymentReason::Cancelled)
        ->and($record->invoice_id)->toBe($invoice->id)
        ->and(clsPayments($invoice))->toBe([])
        ->and(clsIncomes($invoice))->toBe([]);
});

it('records a payment of a link Kokpit does not know as undetermined with no document', function (): void {
    [$invoice] = clsInvoice();
    $event = StripeEvents::checkoutCompleted(['amount_total' => 1_250_000]);
    unset($event['data']['object']['metadata']);

    StripeWebhookSigner::post($this, $event)->assertOk();

    $record = clsOnlyRecord();

    expect($record->type)->toBe(CardPaymentType::Unmatched)
        ->and($record->reason)->toBe(CardPaymentReason::Undetermined)
        ->and($record->invoice_id)->toBeNull()
        ->and($record->payment_link_id)->toBeNull()
        ->and($record->offered_amount_minor)->toBeNull()
        ->and(clsPayments($invoice))->toBe([]);
});

it('records a session whose metadata names another document than its link as undetermined with no document', function (): void {
    [$invoice, $link] = clsInvoice();
    [$other] = clsInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link, ['metadata' => ['invoice_id' => $other->id]]))->assertOk();

    $record = clsOnlyRecord();

    expect($record->reason)->toBe(CardPaymentReason::Undetermined)
        ->and($record->invoice_id)->toBeNull()
        ->and(clsPayments($invoice))->toBe([])
        ->and(clsPayments($other))->toBe([]);
});

it('records a session that names the Stripe link of another link row as undetermined', function (): void {
    [$invoice, $link] = clsInvoice();
    [$other, $otherLink] = clsInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link, ['payment_link' => $otherLink->stripe_link_id]))->assertOk();

    $record = clsOnlyRecord();

    expect($record->reason)->toBe(CardPaymentReason::Undetermined)
        ->and($record->invoice_id)->toBeNull()
        ->and(clsPayments($invoice))->toBe([])
        ->and(clsPayments($other))->toBe([]);
});

it('matches through the link when the metadata is malformed', function (): void {
    [$invoice, $link] = clsInvoice();

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link, ['metadata' => ['invoice_id' => 'not-a-uuid', 'payment_link_row' => 'also-not']]))->assertOk();

    $record = clsOnlyRecord();

    expect($record->type)->toBe(CardPaymentType::Matched)
        ->and($record->invoice_id)->toBe($invoice->id)
        ->and(clsPayments($invoice))->toHaveCount(1);
});

it('matches through the Stripe link id when the metadata is missing', function (): void {
    [$invoice, $link] = clsInvoice();
    $event = StripeEvents::checkoutFor($link);
    unset($event['data']['object']['metadata']);

    StripeWebhookSigner::post($this, $event)->assertOk();

    expect(clsOnlyRecord()->type)->toBe(CardPaymentType::Matched)
        ->and(clsPayments($invoice))->toHaveCount(1);
});

it('stores a card payment of a proforma on the proforma and books no income', function (): void {
    $proforma = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);
    $link = clsLink($proforma);

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $record = clsOnlyRecord();

    expect($record->type)->toBe(CardPaymentType::Matched)
        ->and($record->invoice_id)->toBe($proforma->id)
        ->and(clsPayments($proforma))->toHaveCount(1)
        ->and(clsIncomes($proforma))->toBe([])
        ->and(clsRemaining($proforma))->toBe(0);

    $rows = DB::table('notifications')->where('notifiable_id', $this->admin->getKey())->get();
    $data = json_decode((string) $rows->sole()->data, true, 512, JSON_THROW_ON_ERROR);

    expect($data['body'])->toStartWith('Záloha '.$proforma->number.' byla zaplacena (');
});

it('books a foreign-currency card payment with the CZK figure of the CNB list of the day', function (): void {
    InvoiceFixtures::cnbRate('EUR', IssueContext::today(), '25.125');
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['currency' => 'EUR']));
    $link = clsLink($invoice);

    StripeWebhookSigner::post($this, StripeEvents::checkoutFor($link))->assertOk();

    $record = clsOnlyRecord();
    $incomes = clsIncomes($invoice);

    expect($record->type)->toBe(CardPaymentType::Matched)
        ->and($record->currency)->toBe('EUR')
        ->and($incomes)->toHaveCount(1)
        ->and($incomes[0]->amount_currency)->toBe('EUR')
        ->and($incomes[0]->czk_source)->toBe(CzkSource::Cnb)
        ->and($incomes[0]->czk_minor)->toBe((int) round($link->amount_minor * 25.125));
});

it('fails the job and records nothing when a foreign-currency payment has no CNB list', function (): void {
    // The only list is newer than the payment day of the event, so the day of the payment has none.
    InvoiceFixtures::cnbRate('EUR', '2026-10-09', '25.125');
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['currency' => 'EUR']));
    $link = clsLink($invoice);
    $event = StripeEvents::checkoutFor($link, [], ['created' => CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC')->getTimestamp()]);

    Queue::fake();
    StripeWebhookSigner::post($this, $event)->assertOk();

    $call = app(PartnerContext::class)->runAsSystem(static fn (): WebhookCall => WebhookCall::query()->where('event_id', $event['id'])->firstOrFail());
    $job = new ProcessStripeWebhook($call);
    $failure = null;

    try {
        app(PartnerContext::class)->runAsSystem(static fn () => $job->handle());
    } catch (ValidationException $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(ValidationException::class);

    $job->failed($failure);

    $row = DB::table('webhook_calls')->where('id', $call->getKey())->first();

    expect(clsRecords())->toBe([])
        ->and(clsPayments($invoice))->toBe([])
        ->and(clsIncomes($invoice))->toBe([])
        ->and(clsLink($invoice)->state)->toBe(PaymentLinkState::Active)
        ->and($row?->processed_at)->toBeNull()
        ->and($row?->failed_at)->not->toBeNull()
        ->and($row?->failure_class)->toBe('validation');
});
