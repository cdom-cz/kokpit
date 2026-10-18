<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeEvents;
use Tests\Support\StripeWebhookSigner;

/*
 * A client pays the Stripe link of an invoice and Stripe's signed event books the payment (PA-06; D-10, D-14,
 * research Pattern 5 and Pitfall 6). Every name, amount and Stripe reference is fictional or assembled at runtime.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
});

/**
 * The live link of the document, read as system.
 */
function matchLink(Invoice $invoice): PaymentLink
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->firstOrFail());
}

/**
 * The card payment records of the document, read as system.
 *
 * @return list<CardPayment>
 */
function matchRecords(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => CardPayment::query()->orderBy('created_at')->orderBy('id')->get()->all());
}

/**
 * The payments of the document, read as system.
 *
 * @return list<InvoicePayment>
 */
function matchPayments(Invoice $invoice): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => InvoicePayment::query()->where('invoice_id', $invoice->id)->get()->all());
}

/**
 * The incomes booked for the document, read as system.
 *
 * @return list<Transaction>
 */
function matchIncomes(Invoice $invoice): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => Transaction::query()->where('invoice_id', $invoice->id)->get()->all());
}

/**
 * The bell rows of a user, as the database holds them.
 *
 * @return list<object>
 */
function matchBell(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->getKey())->get()->all();
}

it('books a card payment of the invoice link: payment, income, paid, link off and the Admin told', function (): void {
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = matchLink($invoice);
    $event = StripeEvents::checkoutFor($link);

    expect($link->state)->toBe(PaymentLinkState::Active)
        ->and($link->amount_minor)->toBe(1_250_000);

    StripeWebhookSigner::post($this, $event)->assertOk();

    $records = matchRecords();
    $payments = matchPayments($invoice);

    expect($records)->toHaveCount(1)
        ->and($records[0]->type)->toBe(CardPaymentType::Matched)
        ->and($records[0]->reason)->toBeNull()
        ->and($records[0]->stripe_event_id)->toBe($event['id'])
        ->and($records[0]->stripe_session_id)->toBe($event['data']['object']['id'])
        ->and($records[0]->stripe_payment_intent_id)->toBe($event['data']['object']['payment_intent'])
        ->and($records[0]->stripe_link_id)->toBe($link->stripe_link_id)
        ->and($records[0]->invoice_id)->toBe($invoice->id)
        ->and($records[0]->payment_link_id)->toBe($link->id)
        ->and($records[0]->offered_amount_minor)->toBe(1_250_000)
        ->and($records[0]->offered_currency)->toBe('CZK')
        ->and($payments)->toHaveCount(1)
        ->and($records[0]->invoice_payment_id)->toBe($payments[0]->id)
        ->and($payments[0]->method)->toBe(PaymentMethod::Card)
        ->and($payments[0]->created_by)->toBeNull()
        ->and($payments[0]->amount_minor)->toBe(1_250_000)
        ->and($payments[0]->currency)->toBe('CZK');

    $incomes = matchIncomes($invoice);

    expect($incomes)->toHaveCount(1)
        ->and($incomes[0]->description)->toBe('Platba kartou faktury '.$invoice->number)
        ->and($incomes[0]->category_id)->toBe(app(PartnerContext::class)->runAsSystem(static fn (): string => (string) TransactionCategory::invoicePayments()->getKey()))
        ->and($incomes[0]->invoice_payment_id)->toBe($payments[0]->id);

    $remaining = app(PartnerContext::class)->runAsSystem(fn (): ?int => app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining']);

    expect($remaining)->toBe(0);

    $link = matchLink($invoice);

    expect($link->state)->toBe(PaymentLinkState::Deactivated)
        ->and($link->deactivation_reason)->toBe(PaymentLinkDeactivation::Paid)
        ->and($this->stripe->deactivated())->toContain($link->stripe_link_id);

    $call = app(PartnerContext::class)->runAsSystem(static fn (): WebhookCall => WebhookCall::query()->where('event_id', $event['id'])->firstOrFail());

    expect($call->processed_at)->not->toBeNull()
        ->and($call->failed_at)->toBeNull();
});

it('tells every Admin with the number and the amount only', function (): void {
    $second = Canary::admin();
    $partner = Canary::partnerFor(null);
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
    $event = StripeEvents::checkoutFor(matchLink($invoice));

    StripeWebhookSigner::post($this, $event)->assertOk();

    $amount = Money::ofMinor(1_250_000, 'CZK')->format('cs');

    foreach ([$this->admin, $second] as $admin) {
        $rows = matchBell($admin);

        expect($rows)->toHaveCount(1);

        $data = json_decode((string) $rows[0]->data, true, 512, JSON_THROW_ON_ERROR);

        expect($data['title'])->toBe('Přišla platba kartou')
            ->and($data['body'])->toBe('Doklad '.$invoice->number.' byl zaplacen ('.$amount.').')
            ->and($data['actions'][0]['label'])->toBe('Otevřít doklad')
            ->and($data['actions'][0]['url'])->toContain($invoice->id);

        $text = (string) $rows[0]->data;

        expect($text)->not->toContain($event['data']['object']['id'])
            ->and($text)->not->toContain($event['data']['object']['payment_intent'])
            ->and($text)->not->toContain($event['id'])
            ->and($text)->not->toContain('Jane Example');
    }

    expect(matchBell($partner))->toBe([]);
});

it('reads the session figures defensively', function (): void {
    $event = StripeEvents::checkoutCompleted([
        'amount_total' => '5',
        'currency' => 'czk1',
        'payment_intent' => ['id' => 'pi_expanded'],
        'metadata' => ['invoice_id' => 'not-a-uuid', 'payment_link_row' => 42],
    ], ['created' => 'yesterday']);

    $parsed = StripeEvent::fromPayload($event);

    expect($parsed->amountMinor)->toBeNull()
        ->and($parsed->currency)->toBeNull()
        ->and($parsed->paymentIntentId)->toBeNull()
        ->and($parsed->invoiceId)->toBeNull()
        ->and($parsed->paymentLinkRow)->toBeNull()
        ->and($parsed->created)->toBeNull()
        ->and($parsed->isPaid())->toBeTrue()
        ->and(StripeEvent::fromPayload([])->isPaid())->toBeFalse();

    $good = StripeEvent::fromPayload(StripeEvents::checkoutCompleted(['amount_total' => 500, 'currency' => 'eur']));

    expect($good->amountMinor)->toBe(500)
        ->and($good->currency)->toBe('EUR');
});

it('never reads the payer presentment amount', function (): void {
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
    $event = StripeEvents::checkoutFor(matchLink($invoice), [
        'currency_conversion' => ['amount_subtotal' => 51_000, 'fx_rate' => '0.04'],
        'presentment_amount' => 51_000,
        'presentment_currency' => 'eur',
    ]);

    StripeWebhookSigner::post($this, $event)->assertOk();

    $records = matchRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->type)->toBe(CardPaymentType::Matched)
        ->and($records[0]->amount_minor)->toBe(1_250_000)
        ->and($records[0]->currency)->toBe('CZK')
        ->and($records[0]->reason)->not->toBe(CardPaymentReason::Amount);
});
