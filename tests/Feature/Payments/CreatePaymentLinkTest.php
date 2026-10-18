<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * The queued job that creates the single-use Stripe payment link of an issued document (PA-01; D-05 to D-09, research
 * Pattern 2). The gateway is the in-memory fake; every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * The newest link row of a document, read as a system run.
 */
function linkRowOf(Invoice $invoice): PaymentLink
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->latest('id')->firstOrFail());
}

/**
 * The number of link rows in the whole database.
 */
function linkRowCount(): int
{
    return app(PartnerContext::class)->runAsSystem(static fn (): int => PaymentLink::query()->count());
}

it('creates the single-use Stripe link of an issued invoice through the queued job, for its total', function (): void {
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = linkRowOf($issued);

    expect($link->state)->toBe(PaymentLinkState::Active)
        ->and($link->amount_minor)->toBe($issued->total_minor)
        ->and($link->currency)->toBe('CZK')
        ->and($link->activated_at)->not->toBeNull()
        ->and($link->deactivated_at)->toBeNull()
        ->and($link->failure_class)->toBeNull();

    $created = $this->stripe->created();

    expect($created)->toHaveCount(1);

    $request = $created[0];

    expect($request->idempotencyKey)->toBe($link->id)
        ->and($request->amountMinor)->toBe($issued->total_minor)
        ->and($request->currency)->toBe('CZK')
        ->and($request->productName)->toBe('Faktura '.$issued->number)
        ->and($request->invoiceId)->toBe($issued->id)
        ->and($request->linkRowId)->toBe($link->id)
        ->and($request->confirmationMessage)->toBe('Děkujeme, platba byla přijata. Doklad '.$issued->number.' se označí jako zaplacený během několika minut.')
        ->and($request->inactiveMessage)->toBe('Tento odkaz na platbu už není aktivní. Použijte odkaz z dokladu nebo kontaktujte dodavatele.')
        ->and($link->stripe_link_id)->not->toBeNull()
        ->and($link->stripe_url)->toStartWith('https://');
});

it('writes one pending row for the total in the issue transaction and pushes the job only after the commit', function (): void {
    Queue::fake();

    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = linkRowOf($issued);

    expect($link->state)->toBe(PaymentLinkState::Pending)
        ->and($link->amount_minor)->toBe($issued->total_minor)
        ->and($link->created_by)->not->toBeNull();

    Queue::assertPushed(CreatePaymentLink::class, 1);
    Queue::assertPushed(CreatePaymentLink::class, static fn (CreatePaymentLink $job): bool => $job->linkId === $link->id);
    expect($this->stripe->createCalls())->toBe(0);
});

it('runs no job and leaves no row when the issue transaction is rolled back after the link was opened', function (): void {
    $client = CardPaymentSetup::client();
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $admin = Canary::admin();

    $attempt = static fn () => DB::transaction(static function () use ($draft, $admin): void {
        $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();
        app(DocumentIssuer::class)->issue($locked, IssueContext::now($admin));

        throw new RuntimeException('Forced failure after the issue.');
    });

    expect($attempt)->toThrow(RuntimeException::class);

    expect(linkRowCount())->toBe(0)
        ->and($this->stripe->createCalls())->toBe(0);
});

it('gives a document issued with the switch off neither a row nor a job', function (): void {
    Queue::fake();

    $client = CardPaymentSetup::client(['online_payment_enabled' => false]);
    $issued = CardPaymentSetup::issued($client);

    Queue::assertNothingPushed();
    expect(linkRowCount())->toBe(0)
        ->and($issued->admin?->card_payment_enabled)->toBeFalse()
        ->and($this->stripe->createCalls())->toBe(0);
});

it('gives a credit note neither a row nor a job', function (): void {
    Queue::fake();

    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    Queue::assertPushed(CreatePaymentLink::class, 1);

    InvoiceFixtures::stornoed($issued);

    Queue::assertPushed(CreatePaymentLink::class, 1);
    expect(linkRowCount())->toBe(1);
});

it('names the proforma in the product of its link', function (): void {
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client(), InvoiceKind::Proforma);

    expect($this->stripe->created())->toHaveCount(1)
        ->and($this->stripe->created()[0]->productName)->toBe('Zálohová faktura '.$issued->number);
});

it('does not create a second Stripe link when the job runs again for an active row', function (): void {
    $issued = CardPaymentSetup::issued(CardPaymentSetup::client());
    $link = linkRowOf($issued);
    $before = $link->updated_at;

    CreatePaymentLink::dispatchSync($link->id);

    $after = linkRowOf($issued);

    expect($this->stripe->createCalls())->toBe(1)
        ->and($after->state)->toBe(PaymentLinkState::Active)
        ->and($after->stripe_link_id)->toBe($link->stripe_link_id)
        ->and($after->updated_at?->equalTo($before))->toBeTrue();
});

it('ignores an id that is no UUID and an id of no row', function (): void {
    CreatePaymentLink::dispatchSync('not-a-uuid');
    CreatePaymentLink::dispatchSync((string) Str::uuid7());

    expect($this->stripe->createCalls())->toBe(0);
});
