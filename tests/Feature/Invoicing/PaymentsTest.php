<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * Manual payments of invoices and proformas (IN-05, FI-03; D-17, D-18, D-19): several payments, warnings that never
 * block, income only where it belongs, the editable income date and category. Every name and amount is fictional.
 */

/**
 * An issued CZK invoice of 12 500,00 Kč (or the given kind) of a new client, acting as a new Admin.
 *
 * @return array{0: User, 1: Invoice, 2: Client}
 */
function payIssued(InvoiceKind $kind = InvoiceKind::Invoice, string $currency = 'CZK'): array
{
    $admin = Canary::admin();
    $client = InvoiceFixtures::client(['currency' => $currency]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')], $kind);
    test()->actingAs($admin);

    return [$admin, $invoice, $client];
}

/**
 * The field errors of a ValidationException a callback raises, or null when it raises nothing.
 *
 * @return array<string, list<string>>|null
 */
function payErrors(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

/**
 * @return array{paid: int, credited: int, remaining: int|null}
 */
function payBalance(Invoice $invoice): array
{
    return app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id];
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

describe('partial and repeated payments', function (): void {
    it('derives unpaid, partly paid and paid from the sum and books one income per payment', function (): void {
        [$admin, $invoice] = payIssued();
        $record = app(RecordPayment::class);

        expect(payBalance($invoice))->toBe(['paid' => 0, 'credited' => 0, 'remaining' => 1250000]);

        $record->handle($admin, $invoice, ['amount' => '5 000,00', 'paid_on' => '2026-10-12']);
        expect(payBalance($invoice))->toBe(['paid' => 500000, 'credited' => 0, 'remaining' => 750000]);

        $record->handle($admin, $invoice, ['amount' => '7500', 'paid_on' => '2026-10-13', 'method' => 'cash', 'note' => ' Paid at the desk ']);
        $payments = InvoicePayment::query()->where('invoice_id', $invoice->id)->get();

        expect(payBalance($invoice))->toBe(['paid' => 1250000, 'credited' => 0, 'remaining' => 0])
            ->and($payments)->toHaveCount(2)
            ->and($payments->last()?->method->value)->toBe('cash')
            ->and($payments->last()?->note)->toBe('Paid at the desk')
            ->and(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(2)
            ->and((int) Transaction::query()->where('invoice_id', $invoice->id)->sum('amount_minor'))->toBe(1250000);
    });

    it('allows a payment dated before the issue date and refuses one dated in the future', function (): void {
        [$admin, $invoice] = payIssued();

        $early = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '100', 'paid_on' => '2026-10-01']);
        $future = payErrors(fn () => app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '100', 'paid_on' => '2026-10-15']));
        $today = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '100', 'paid_on' => '2026-10-14']);

        expect($early->paid_on->toDateString())->toBe('2026-10-01')
            ->and($future)->toBe(['paid_on' => [__('invoices.errors.payment_date_future')]])
            ->and($today->paid_on->toDateString())->toBe('2026-10-14')
            ->and(InvoicePayment::query()->count())->toBe(2);
    });
});

describe('warnings', function (): void {
    it('reports an already paid document and an amount above the remaining one, and never blocks the payment', function (): void {
        [$admin, $invoice] = payIssued();
        $record = app(RecordPayment::class);
        $czk = static fn (int $minor): Money => Money::ofMinor($minor, 'CZK');

        $record->handle($admin, $invoice, ['amount' => '5000', 'paid_on' => '2026-10-13']);

        expect($record->warnings($invoice, $czk(750000)))->toBe([])
            ->and($record->warnings($invoice, $czk(750001)))->toBe(['above_remaining'])
            ->and($record->warnings($invoice, Money::ofMinor(1, 'EUR')))->toBe([]);

        $record->handle($admin, $invoice, ['amount' => '7500', 'paid_on' => '2026-10-13']);

        expect($record->warnings($invoice, $czk(100)))->toBe(['already_paid']);

        $extra = $record->handle($admin, $invoice, ['amount' => '100', 'paid_on' => '2026-10-14']);

        expect($extra->amount_minor)->toBe(10000)
            ->and(payBalance($invoice))->toBe(['paid' => 1260000, 'credited' => 0, 'remaining' => 0])
            ->and(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(3);
    });

    it('stores a payment above the remaining amount when it is submitted', function (): void {
        [$admin, $invoice] = payIssued();

        $payment = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '20000', 'paid_on' => '2026-10-13']);

        expect($payment->amount_minor)->toBe(2000000)
            ->and(payBalance($invoice)['remaining'])->toBe(0);
    });
});

describe('proformas', function (): void {
    it('stores a proforma payment as a payment only and books no income', function (): void {
        [$admin, $proforma] = payIssued(InvoiceKind::Proforma);

        $payment = app(RecordPayment::class)->handle($admin, $proforma, [
            'amount' => '12500',
            'paid_on' => '2026-10-13',
            'income_date' => '2026-10-12',
            'category_id' => 'not-an-id',
        ]);

        expect($payment->invoice_id)->toBe($proforma->id)
            ->and(payBalance($proforma))->toBe(['paid' => 1250000, 'credited' => 0, 'remaining' => 0])
            ->and(Transaction::query()->count())->toBe(0);
    });
});

describe('documents that cannot be paid', function (): void {
    it('refuses a payment on a draft, a credit note and a cancelled invoice and stores nothing', function (): void {
        [$admin, $invoice, $client] = payIssued();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')], actor: $admin);
        $credit = InvoiceFixtures::stornoed($invoice);
        $expected = ['payment' => [__('invoices.errors.payment_not_allowed')]];
        $attempt = static fn (Invoice $document): ?array => payErrors(
            fn () => app(RecordPayment::class)->handle($admin, $document, ['amount' => '100', 'paid_on' => '2026-10-13']),
        );

        expect($attempt($draft))->toBe($expected)
            ->and($attempt($credit))->toBe($expected)
            ->and($attempt($invoice))->toBe($expected)
            ->and(InvoicePayment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });
});

describe('validation', function (): void {
    it('answers each bad value under its own key and stores nothing', function (): void {
        [$admin, $invoice] = payIssued();
        $expense = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->firstOrFail();
        $archived = TransactionCategory::query()->where('type', 'income')->whereNull('parent_id')->where('is_system', false)->firstOrFail();
        $archived->forceFill(['archived_at' => now()])->save();
        $valid = ['amount' => '100', 'paid_on' => '2026-10-13'];
        $attempt = static fn (array $change): ?array => payErrors(
            fn () => app(RecordPayment::class)->handle($admin, $invoice, [...$valid, ...$change]),
        );
        $amount = ['amount' => [__('invoices.errors.payment_amount')]];

        expect($attempt(['amount' => '0']))->toBe($amount)
            ->and($attempt(['amount' => '-5']))->toBe($amount)
            ->and($attempt(['amount' => '10,005']))->toBe($amount)
            ->and($attempt(['amount' => 'abc']))->toBe($amount)
            ->and($attempt(['amount' => null]))->toBe($amount)
            ->and($attempt(['paid_on' => '2026-10-15']))->toBe(['paid_on' => [__('invoices.errors.payment_date_future')]])
            ->and($attempt(['paid_on' => 'yesterday']))->toBe(['paid_on' => [__('invoices.errors.date_invalid')]])
            ->and($attempt(['income_date' => '2026-02-30']))->toBe(['income_date' => [__('invoices.errors.date_invalid')]])
            ->and($attempt(['method' => 'barter']))->toBe(['method' => [__('invoices.errors.payment_method')]])
            ->and($attempt(['note' => str_repeat('x', 501)]))->toBe(['note' => [__('invoices.errors.payment_note')]])
            ->and($attempt(['category_id' => $expense->id]))->toBe(['category_id' => [__('invoices.errors.payment_category')]])
            ->and($attempt(['category_id' => $archived->id]))->toBe(['category_id' => [__('invoices.errors.payment_category')]])
            ->and($attempt(['category_id' => '018f0000-0000-7000-8000-000000000000']))->toBe(['category_id' => [__('invoices.errors.payment_category')]])
            ->and(InvoicePayment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });
});

describe('the editable income date and category', function (): void {
    it('books the income on its own date and in another income category while the payment keeps its date', function (): void {
        [$admin, $invoice] = payIssued();
        $other = TransactionCategory::query()->where('type', 'income')->whereNull('parent_id')->where('is_system', false)->firstOrFail();

        $payment = app(RecordPayment::class)->handle($admin, $invoice, [
            'amount' => '12500',
            'paid_on' => '2026-10-13',
            'income_date' => '2026-10-11',
            'category_id' => $other->id,
        ]);
        $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

        expect($payment->paid_on->toDateString())->toBe('2026-10-13')
            ->and($income->transaction_date->toDateString())->toBe('2026-10-11')
            ->and($income->category_id)->toBe($other->id);
    });
});

describe('a foreign currency', function (): void {
    it('books the CZK figure of the CNB list of the income date', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-09', '25.125');
        [$admin, $invoice] = payIssued(currency: 'EUR');

        $payment = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '12 500,00', 'paid_on' => '2026-10-13']);
        $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

        expect($payment->currency)->toBe('EUR')
            ->and($income->amount_currency)->toBe('EUR')
            ->and($income->amount_minor)->toBe(1250000)
            ->and($income->czk_source)->toBe(CzkSource::Cnb)
            ->and($income->czk_minor)->toBe((int) round(1250000 * 25.125))
            ->and($income->exchange_rate_date?->toDateString())->toBe('2026-10-09');
    });

    it('fails the payment under the date when no list exists and stores nothing', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-09', '25.125');
        [$admin, $invoice] = payIssued(currency: 'EUR');
        $record = static fn (array $data): ?array => payErrors(fn () => app(RecordPayment::class)->handle($admin, $invoice, $data));

        $onPaymentDate = $record(['amount' => '100', 'paid_on' => '2026-10-05']);
        $onIncomeDate = $record(['amount' => '100', 'paid_on' => '2026-10-13', 'income_date' => '2026-10-05']);

        expect($onPaymentDate)->toBe(['paid_on' => [__('invoices.errors.cnb_missing', ['currency' => 'EUR', 'date' => '5. 10. 2026'])]])
            ->and($onIncomeDate)->toBe(['income_date' => [__('invoices.errors.cnb_missing', ['currency' => 'EUR', 'date' => '5. 10. 2026'])]])
            ->and($onPaymentDate['paid_on'][0])->toContain('kurz ČNB')
            ->and(InvoicePayment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });
});
