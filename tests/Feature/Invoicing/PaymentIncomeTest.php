<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * A payment of an invoice is booked as income in the finance module in the same transaction (FI-03, D-18, D-19).
 * Every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

it('records a full transfer payment, leaves nothing owed and books the income in the system category', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $this->actingAs($admin);

    $payment = app(RecordPayment::class)->handle($admin, $invoice, [
        'amount' => '12 500,00',
        'paid_on' => '2026-10-13',
        'method' => 'transfer',
    ]);

    $balance = app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id];
    $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

    expect($payment->amount_minor)->toBe(1250000)
        ->and($payment->currency)->toBe('CZK')
        ->and($payment->paid_on->toDateString())->toBe('2026-10-13')
        ->and($payment->method->value)->toBe('transfer')
        ->and($payment->created_by)->toBe($admin->id)
        ->and($balance)->toBe(['paid' => 1250000, 'credited' => 0, 'remaining' => 0])
        ->and(Transaction::query()->count())->toBe(1)
        ->and($income->type)->toBe(TransactionType::Income)
        ->and($income->category_id)->toBe(TransactionCategory::invoicePayments()->id)
        ->and($income->transaction_date->toDateString())->toBe('2026-10-13')
        ->and($income->amount_minor)->toBe(1250000)
        ->and($income->amount_currency)->toBe('CZK')
        ->and($income->czk_minor)->toBe(1250000)
        ->and($income->czk_source)->toBe(CzkSource::Identity)
        ->and($income->client_id)->toBe($client->id)
        ->and($income->description)->toBe('Platba faktury '.$invoice->number)
        ->and($income->document_number)->toBe($invoice->number)
        ->and($income->counterparty)->toBe($client->name)
        ->and($income->invoice_id)->toBe($invoice->id)
        ->and($income->invoice_payment_id)->toBe($payment->id)
        ->and($income->isPaymentIncome())->toBeTrue();
});

it('refuses a Partner and stores nothing', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '1000.00')]);
    $partner = Canary::partnerFor($client->id);
    $this->actingAs($partner);

    expect(fn () => app(RecordPayment::class)->handle($partner, $invoice, ['amount' => '1000', 'paid_on' => '2026-10-13']))
        ->toThrow(AuthorizationException::class);

    $this->actingAs($admin);

    expect(InvoicePayment::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('lets only the Admin or a system run read the balance', function (): void {
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '1000.00')]);
    $this->actingAs(Canary::admin());

    expect(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining'])->toBe(100000)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): array => app(InvoiceBalances::class)->for([$invoice->id])))
        ->toHaveKey($invoice->id);
});
