<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RecordRefundEvent;
use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Stripe\StripeEvent;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\CardPaymentResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\StripeEvents;
use Tests\Support\StripeWebhookSigner;
use Tests\TestCase;

/*
 * A refund announced by Stripe (PA-08; D-14; research Pitfall 11): recorded, linked to the payment it returns when that
 * is known, alerted to every Admin, and never a change of the document, the payment or the income. Every name, amount
 * and reference is fictional or assembled at runtime.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    Canary::admin();
    Canary::admin();
});

/**
 * A paid card-on invoice of 12 500,00 Kč: the invoice, its link and the signed session event that paid it.
 *
 * @return array{0: Invoice, 1: PaymentLink, 2: array<string, mixed>}
 */
function rfPaid(TestCase $test): array
{
    $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->firstOrFail());
    $event = StripeEvents::checkoutFor($link);

    StripeWebhookSigner::post($test, $event)->assertOk();

    return [$invoice, $link, $event];
}

/**
 * Every card payment record, oldest first, read as system.
 *
 * @return list<CardPayment>
 */
function rfRecords(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => CardPayment::query()->orderBy('created_at')->orderBy('id')->get()->all());
}

/**
 * Every Admin the application has, read as system.
 *
 * @return list<User>
 */
function rfAdmins(): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => User::query()->role(RoleName::Admin->value)->get()->all());
}

/**
 * The bell rows of one user with the given title, decoded.
 *
 * @return list<array<string, mixed>>
 */
function rfBellTitled(User $user, string $title): array
{
    $rows = DB::table('notifications')->where('notifiable_id', $user->getKey())->orderBy('created_at')->orderBy('id')->get()
        ->map(static fn (object $row): array => json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR))
        ->all();

    return array_values(array_filter($rows, static fn (array $row): bool => ($row['title'] ?? null) === $title));
}

/**
 * Subject, recipient and the whole text of every mail with the given subject.
 *
 * @return list<array{to: string, text: string}>
 */
function rfMailsTitled(string $subject): array
{
    $mails = [];

    foreach (Mail::getSymfonyTransport()->messages() as $sent) {
        $original = $sent->getOriginalMessage();
        assert($original instanceof Email);

        if ((string) $original->getSubject() === $subject) {
            $mails[] = ['to' => $original->getTo()[0]->getAddress(), 'text' => (string) $original->getTextBody().(string) $original->getHtmlBody()];
        }
    }

    return $mails;
}

/**
 * What the books hold for the document: its row, its payments and its incomes, as the database has them.
 *
 * @return array<string, mixed>
 */
function rfBooks(Invoice $invoice): array
{
    return [
        'invoice' => DB::table('invoices')->where('id', $invoice->id)->first(),
        'payments' => DB::table('invoice_payments')->where('invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        'incomes' => DB::table('transactions')->where('invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        'link' => DB::table('payment_links')->where('invoice_id', $invoice->id)->first(),
    ];
}

it('records a refund of a matched payment with its document and link, alerts every Admin and leaves the books alone', function (): void {
    [$invoice, $link, $paid] = rfPaid($this);
    $books = rfBooks($invoice);
    $refund = StripeEvents::refundCreated($paid['data']['object']['payment_intent'], 500_000);

    StripeWebhookSigner::post($this, $refund)->assertOk();

    $records = rfRecords();
    $record = $records[1];
    $amount = Money::ofMinor(500_000, 'CZK')->format('cs');
    $url = CardPaymentResource::getUrl('view', ['record' => $record]);
    $body = 'Vrácení '.$amount.' se týká platby kartou. Doklad ani příjem se nezměnily. Zkontrolujte ho v přehledu plateb.';

    expect($records)->toHaveCount(2)
        ->and(preg_replace('/\s+/u', ' ', $amount))->toBe('5 000,00 Kč')
        ->and($record->type)->toBe(CardPaymentType::Refund)
        ->and($record->reason)->toBeNull()
        ->and($record->amount_minor)->toBe(500_000)
        ->and($record->currency)->toBe('CZK')
        ->and($record->invoice_id)->toBe($invoice->id)
        ->and($record->payment_link_id)->toBe($link->id)
        ->and($record->invoice_payment_id)->toBeNull()
        ->and($record->stripe_refund_id)->toBe($refund['data']['object']['id'])
        ->and($record->stripe_event_id)->toBe($refund['id'])
        ->and($record->stripe_payment_intent_id)->toBe($paid['data']['object']['payment_intent'])
        ->and($record->isUnresolved())->toBeTrue()
        ->and(rfBooks($invoice))->toEqual($books);

    foreach (rfAdmins() as $admin) {
        $rows = rfBellTitled($admin, 'Stripe oznámil vrácení platby');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['body'])->toBe($body)
            ->and($rows[0]['actions'][0]['label'])->toBe('Otevřít platbu')
            ->and($rows[0]['actions'][0]['url'])->toBe($url);
    }

    $mails = rfMailsTitled('Kokpit: Stripe oznámil vrácení platby');

    expect($mails)->toHaveCount(count(rfAdmins()))
        ->and($mails[0]['text'])->toContain($body)
        ->and($mails[0]['text'])->toContain($url)
        ->and(json_encode([$rows, $mails], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->not->toContain($refund['data']['object']['id'])
        ->not->toContain($refund['data']['object']['payment_intent'])
        ->not->toContain($refund['data']['object']['charge']);

    $call = app(PartnerContext::class)->runAsSystem(static fn (): WebhookCall => WebhookCall::query()->where('event_id', $refund['id'])->firstOrFail());

    expect($call->processed_at)->not->toBeNull();
});

it('shows the refund record in the tab Vrácení and in the queue Nevyřešené', function (): void {
    [$invoice, $link, $paid] = rfPaid($this);

    StripeWebhookSigner::post($this, StripeEvents::refundCreated($paid['data']['object']['payment_intent'], 500_000))->assertOk();

    $inTab = static fn (CardPaymentTab $tab): int => app(PartnerContext::class)->runAsSystem(static fn (): int => $tab->constrain(CardPayment::query())->count());

    expect($inTab(CardPaymentTab::Refund))->toBe(1)
        ->and($inTab(CardPaymentTab::Unresolved))->toBe(1)
        ->and($inTab(CardPaymentTab::Matched))->toBe(1);
});

it('ignores the same refund event delivered again, with no new record and no new notice', function (): void {
    [$invoice, $link, $paid] = rfPaid($this);
    $refund = StripeEvents::refundCreated($paid['data']['object']['payment_intent'], 500_000);

    StripeWebhookSigner::post($this, $refund)->assertOk();
    StripeWebhookSigner::post($this, $refund)->assertOk();

    $handled = app(PartnerContext::class)->runAsSystem(static fn (): ?CardPayment => DB::transaction(static fn (): ?CardPayment => app(RecordRefundEvent::class)->handle(StripeEvent::fromPayload($refund))));

    expect($handled)->toBeNull()
        ->and(rfRecords())->toHaveCount(2)
        ->and(rfBellTitled(rfAdmins()[0], 'Stripe oznámil vrácení platby'))->toHaveCount(1)
        ->and(rfMailsTitled('Kokpit: Stripe oznámil vrácení platby'))->toHaveCount(count(rfAdmins()));
});

it('ignores a second event of the same refund id', function (): void {
    [$invoice, $link, $paid] = rfPaid($this);
    $refund = StripeEvents::refundCreated($paid['data']['object']['payment_intent'], 500_000);

    StripeWebhookSigner::post($this, $refund)->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::refundCreated($paid['data']['object']['payment_intent'], 500_000, 'czk', ['id' => $refund['data']['object']['id']]))->assertOk();

    expect(rfRecords())->toHaveCount(2);
});

it('records a second refund of the same payment intent as a second record with its own notice', function (): void {
    [$invoice, $link, $paid] = rfPaid($this);
    $intent = $paid['data']['object']['payment_intent'];

    StripeWebhookSigner::post($this, StripeEvents::refundCreated($intent, 500_000))->assertOk();
    StripeWebhookSigner::post($this, StripeEvents::refundCreated($intent, 250_000))->assertOk();

    $records = rfRecords();

    expect($records)->toHaveCount(3)
        ->and($records[1]->type)->toBe(CardPaymentType::Refund)
        ->and($records[2]->type)->toBe(CardPaymentType::Refund)
        ->and($records[2]->amount_minor)->toBe(250_000)
        ->and($records[2]->invoice_id)->toBe($invoice->id)
        ->and(rfBellTitled(rfAdmins()[0], 'Stripe oznámil vrácení platby'))->toHaveCount(2);
});

it('records a refund of an unknown payment intent with no document and alerts about it', function (): void {
    $refund = StripeEvents::refundCreated(StripeEvents::token('pi'), 300_000, 'czk');

    StripeWebhookSigner::post($this, $refund)->assertOk();

    $records = rfRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->type)->toBe(CardPaymentType::Refund)
        ->and($records[0]->invoice_id)->toBeNull()
        ->and($records[0]->payment_link_id)->toBeNull()
        ->and($records[0]->amount_minor)->toBe(300_000)
        ->and($records[0]->isUnresolved())->toBeTrue()
        ->and(rfBellTitled(rfAdmins()[0], 'Stripe oznámil vrácení platby'))->toHaveCount(1)
        ->and(rfMailsTitled('Kokpit: Stripe oznámil vrácení platby'))->toHaveCount(count(rfAdmins()));
});

it('maps the refund event type to its handler', function (): void {
    expect(ProcessStripeWebhook::handlers()['refund.created'])->toBe(RecordRefundEvent::class);
});
