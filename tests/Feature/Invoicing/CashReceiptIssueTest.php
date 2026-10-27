<?php

declare(strict_types=1);

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\IssueCashReceipt;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Sequences\SequenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\FailingAfterAllocationSequenceAllocator;
use Tests\Support\InvoiceFixtures;

/*
 * The automatic cash receipt (Phase 15; D-06, D-07, D-09, D-11 data): every cash payment recorded through the write core
 * issues exactly one numbered receipt in the same transaction, a carried copy and the other methods issue none, and a
 * failure after the number was taken leaves no payment, no receipt and no gap. Every name and amount is fictional.
 */

beforeEach(function (): void {
    Queue::fake();
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * An issued CZK document of 12 500,00 Kc of a new client (invoice language cs unless given), and the Admin acting.
 *
 * @return array{0: User, 1: Invoice}
 */
function cashReceiptIssueDocument(InvoiceKind $kind = InvoiceKind::Invoice, string $language = 'cs'): array
{
    $admin = Canary::admin();
    $client = InvoiceFixtures::client(['invoice_language' => $language]);
    $document = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')], $kind);
    test()->actingAs($admin);

    return [$admin, $document];
}

/**
 * Records a payment as the Admin through the write core.
 */
function cashReceiptIssuePay(User $admin, Invoice $document, string $amount, string $method = 'cash', string $paidOn = '2026-10-14'): InvoicePayment
{
    return app(RecordPayment::class)->handle($admin, $document, ['amount' => $amount, 'paid_on' => $paidOn, 'method' => $method]);
}

/**
 * The receipts of a document, in the order they were issued.
 *
 * @return list<CashReceipt>
 */
function cashReceiptIssueReceipts(Invoice $document): array
{
    return CashReceipt::query()->where('invoice_id', $document->id)->orderBy('number')->get()->all();
}

/**
 * The activity rows a receipt wrote, oldest first.
 *
 * @return list<Activity>
 */
function cashReceiptIssueActivity(CashReceipt $receipt): array
{
    return Activity::query()
        ->where('subject_type', 'cash_receipt')
        ->where('subject_id', $receipt->id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

it('issues one numbered receipt with the amount, the snapshots and the number of the paid invoice', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();

    $payment = cashReceiptIssuePay($admin, $invoice, '1500');
    $invoice->refresh();
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();

    expect($receipt->number)->toBe('PPD2026'.'0001')
        ->and($receipt->document_number)->toBe($invoice->number)
        ->and($receipt->issued_on->toDateString())->toBe('2026-10-14')
        ->and($receipt->received_on->toDateString())->toBe('2026-10-14')
        ->and($receipt->amount_minor)->toBe(150000)
        ->and($receipt->currency)->toBe('CZK')
        ->and($receipt->language)->toBe(InvoiceLanguage::Czech)
        ->and($receipt->purpose)->toBe('Úhrada faktury č. '.$invoice->number)
        ->and($receipt->supplier_snapshot)->toBe($invoice->supplier_snapshot)
        ->and($receipt->customer_snapshot)->toBe($invoice->customer_snapshot)
        ->and($receipt->appearance_snapshot)->toBe($invoice->appearance_snapshot)
        ->and($receipt->lock_state)->toBe(CashReceiptLockState::Open)
        ->and($receipt->cancelled_at)->toBeNull()
        ->and($receipt->invoice_id)->toBe($invoice->id)
        ->and($receipt->client_id)->toBe($invoice->client_id)
        ->and(CashReceipt::query()->count())->toBe(1);
});

it('writes one Admin-only issued row with fixed keys and no purpose text', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();

    $payment = cashReceiptIssuePay($admin, $invoice, '1500');
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();
    $rows = cashReceiptIssueActivity($receipt);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->event)->toBe('cash_receipt_issued')
        ->and($rows[0]->log_name)->toBe('invoice')
        ->and($rows[0]->causer_id)->toBe($admin->id);

    $attributes = (array) $rows[0]->attribute_changes?->get('attributes');

    expect(array_keys($attributes))->toBe(['number', 'document_number', 'invoice_id', 'amount_minor', 'currency', 'received_on'])
        ->and($attributes['number'])->toBe($receipt->number)
        ->and($attributes['amount_minor'])->toBe(150000)
        ->and(json_encode($rows[0]->attribute_changes).json_encode($rows[0]->properties))->not->toContain('Úhrada');
});

it('issues a receipt in the language of the client for a cash payment of a proforma', function (): void {
    [$admin, $proforma] = cashReceiptIssueDocument(InvoiceKind::Proforma, 'en');

    $payment = cashReceiptIssuePay($admin, $proforma, '12500');
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();

    expect($receipt->language)->toBe(InvoiceLanguage::English)
        ->and($receipt->purpose)->toBe('Payment of proforma invoice no. '.$proforma->number)
        ->and($receipt->document_number)->toBe($proforma->number)
        ->and($receipt->invoice_id)->toBe($proforma->id);
});

it('issues a receipt for a cash payment of an invoice that is paid by transfer, because the payment method decides', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();

    cashReceiptIssuePay($admin, $invoice, '500');

    expect(cashReceiptIssueReceipts($invoice))->toHaveCount(1);
});

it('gives each partial cash payment its own receipt, with its own amount and the next number', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();

    cashReceiptIssuePay($admin, $invoice, '5000');
    cashReceiptIssuePay($admin, $invoice, '3000');
    $receipts = cashReceiptIssueReceipts($invoice);

    expect($receipts)->toHaveCount(2)
        ->and(array_map(static fn (CashReceipt $r): string => $r->number, $receipts))->toBe(['PPD2026'.'0001', 'PPD2026'.'0002'])
        ->and(array_map(static fn (CashReceipt $r): int => $r->amount_minor, $receipts))->toBe([500000, 300000])
        ->and(InvoicePayment::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
});

it('issues no receipt for a transfer payment, also on a cash document', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();
    $cashDraft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')], InvoiceKind::Invoice, $admin);
    $cashDraft = app(UpdateDraft::class)->handle($admin, $cashDraft, ['payment_method' => 'cash']);
    $cashDocument = app(IssueDocument::class)->handle($admin, $cashDraft);

    cashReceiptIssuePay($admin, $invoice, '1000', 'transfer');
    cashReceiptIssuePay($admin, $cashDocument, '1000', 'transfer');

    expect(CashReceipt::query()->count())->toBe(0)
        ->and(InvoicePayment::query()->count())->toBe(2);
});

it('issues no receipt for a card payment recorded by the system', function (): void {
    [, $invoice] = cashReceiptIssueDocument();
    $facts = new PaymentFacts(Money::fromMajor('12500.00', 'CZK'), '2026-10-14', PaymentMethod::Card, '2026-10-14', null, null);

    DB::transaction(function () use ($invoice, $facts): void {
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        app(RecordPayment::class)->recordLocked($locked, $facts, null);
    });

    expect(CashReceipt::query()->count())->toBe(0)
        ->and(InvoicePayment::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('issues no new receipt when a proforma paid in cash is converted, the receipt stays the proforma\'s', function (): void {
    [$admin, $proforma] = cashReceiptIssueDocument(InvoiceKind::Proforma);

    cashReceiptIssuePay($admin, $proforma, '12500');
    expect(CashReceipt::query()->count())->toBe(1);

    $invoice = app(ConvertProforma::class)->handle($admin, $proforma);
    $copy = InvoicePayment::query()->where('invoice_id', $invoice->id)->sole();

    expect($copy->method)->toBe(PaymentMethod::Cash)
        ->and($copy->carried_from_payment_id)->not->toBeNull()
        ->and(CashReceipt::query()->count())->toBe(1)
        ->and(CashReceipt::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
        ->and(CashReceipt::query()->where('invoice_payment_id', $copy->id)->exists())->toBeFalse()
        ->and(CashReceipt::query()->sole()->invoice_id)->toBe($proforma->id);
});

it('takes the number from the year of the issue day and keeps the payment date of a back-dated payment', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();

    $payment = cashReceiptIssuePay($admin, $invoice, '1500', 'cash', '2025-12-30');
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();

    expect($receipt->number)->toBe('PPD2026'.'0001')
        ->and($receipt->received_on->toDateString())->toBe('2025-12-30')
        ->and($receipt->issued_on->toDateString())->toBe('2026-10-14');
});

it('refuses to issue a receipt for a payment that is not cash or belongs to another document', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();
    $transfer = cashReceiptIssuePay($admin, $invoice, '1000', 'transfer');

    expect(fn () => DB::transaction(fn () => app(IssueCashReceipt::class)->handle($invoice, $transfer, $admin)))
        ->toThrow(LogicException::class);

    [, $other] = cashReceiptIssueDocument();
    $cash = cashReceiptIssuePay($admin, $invoice, '1000');

    expect(fn () => DB::transaction(fn () => app(IssueCashReceipt::class)->handle($other, $cash, $admin)))
        ->toThrow(LogicException::class);
    expect(CashReceipt::query()->count())->toBe(1);
});

it('rolls the payment, the income and the receipt back when something fails after the number was taken, and leaves no gap', function (): void {
    [$admin, $invoice] = cashReceiptIssueDocument();

    app()->instance(SequenceAllocator::class, new FailingAfterAllocationSequenceAllocator([1]));
    app()->forgetInstance(DocumentNumbering::class);
    app()->forgetInstance(IssueCashReceipt::class);
    app()->forgetInstance(RecordPayment::class);

    expect(fn () => cashReceiptIssuePay($admin, $invoice, '1500'))
        ->toThrow(RuntimeException::class, FailingAfterAllocationSequenceAllocator::MESSAGE);
    expect(InvoicePayment::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
        ->and(DB::table('transactions')->where('invoice_id', $invoice->id)->count())->toBe(0)
        ->and(CashReceipt::query()->count())->toBe(0)
        ->and(DB::table('number_sequences')->where('scope_key', 'like', 'cash_receipt:%')->count())->toBe(0);

    app()->instance(SequenceAllocator::class, new SequenceAllocator);
    app()->forgetInstance(DocumentNumbering::class);
    app()->forgetInstance(IssueCashReceipt::class);
    app()->forgetInstance(RecordPayment::class);

    cashReceiptIssuePay($admin, $invoice, '1500');

    expect(CashReceipt::query()->sole()->number)->toBe('PPD2026'.'0001');
});
