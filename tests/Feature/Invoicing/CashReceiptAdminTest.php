<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Invoicing\Support\CashReceiptPdfUrl;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * What the Admin does with a cash receipt in the Platby tab (Phase 15; D-07, D-08, D-10, D-13; AS-7, AS-10, AS-12;
 * P15-10, P15-11, P15-12, P15-07; threats T-15-38, T-15-39, T-15-40): the receipt and its state next to the payment,
 * the download, the cancel with a reason before the payment can go, the warning for a payment without a valid
 * receipt. Every name and amount is fictional.
 */

beforeEach(function (): void {
    Queue::fake();
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    Mail::getSymfonyTransport()->flush();
});

/**
 * The Platby tab of an issued document.
 */
function cashReceiptAdminTab(Invoice $invoice): Testable
{
    return Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class]);
}

/**
 * Records a cash payment of 1 500 CZK on a new issued document of a client (with an invoice e-mail address unless the
 * address is given as null) and returns the document, the payment and its receipt.
 *
 * @return array{0: Invoice, 1: InvoicePayment, 2: CashReceipt}
 */
function cashReceiptAdminPay(InvoiceKind $kind = InvoiceKind::Invoice, ?string $email = 'default'): array
{
    $address = $email === 'default' ? exampleEmail('client-invoice') : $email;
    $client = InvoiceFixtures::client(['name' => 'Example Client s.r.o.', 'invoice_email' => $address]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')], $kind);

    $payment = app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => '1500', 'paid_on' => '2026-10-14', 'method' => 'cash']);
    $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();

    return [$invoice, $payment, $receipt];
}

describe('the tracer: the receipt next to the payment, the download, and the cancel before the delete (D-08, D-13)', function (): void {
    it('shows the receipt number and the open state next to a cash payment', function (): void {
        [$invoice, , $receipt] = cashReceiptAdminPay();

        cashReceiptAdminTab($invoice)
            ->assertSee($receipt->number)
            ->assertSee('Otevřený')
            ->assertDontSee('Platba bez platného dokladu');
    });

    it('redirects the download to a signed link of the receipt route, built at click time', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();

        $tab = cashReceiptAdminTab($invoice);

        // The page itself never carries the signed link (T-15-40).
        $tab->assertDontSee('signature=');

        $component = $tab->callAction(TestAction::make('downloadCashReceipt')->table($payment));
        $target = $component->effects['redirect'] ?? null;

        expect($target)->toBeString()
            ->and(parse_url($target, PHP_URL_PATH))->toBe(parse_url(CashReceiptPdfUrl::download($receipt, $this->admin), PHP_URL_PATH));

        $this->get($target)->assertOk()->assertHeader('content-type', 'application/pdf');
    });

    it('disables the delete while the receipt is valid and names the receipt', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();

        $tab = cashReceiptAdminTab($invoice)
            ->assertTableActionDisabled('deletePayment', $payment)
            ->assertSee(__('cash_receipts.admin.delete_blocked', ['number' => $receipt->number]));

        $tab->callAction(TestAction::make('deletePayment')->table($payment));

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeTrue();
    });

    it('refuses a reason of five characters under the field and keeps the receipt valid', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();

        cashReceiptAdminTab($invoice)
            ->callAction(TestAction::make('cancelCashReceipt')->table($payment), ['reason' => 'Chyba'])
            ->assertHasActionErrors(['reason']);

        expect(CashReceipt::query()->findOrFail($receipt->id)->cancelled_at)->toBeNull();
    });

    it('cancels the receipt with a reason, warns about the payment without a valid receipt, and then lets the payment go', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();
        $reason = 'Chybně zadaná platba v hotovosti';

        $tab = cashReceiptAdminTab($invoice)
            ->callAction(TestAction::make('cancelCashReceipt')->table($payment), ['reason' => $reason])
            ->assertHasNoActionErrors()
            ->assertDispatched('invoice-payment-saved');

        $row = CashReceipt::query()->findOrFail($receipt->id);

        expect($row->cancelled_at)->not->toBeNull()
            ->and(CashReceiptAudit::cancelReason($row))->toBe($reason);

        $tab->assertSee('Zrušený')
            ->assertSee($reason)
            ->assertSee('Platba bez platného dokladu')
            ->assertTableActionEnabled('deletePayment', $payment)
            ->assertTableActionHidden('cancelCashReceipt', $payment);

        $tab->callAction(TestAction::make('deletePayment')->table($payment))->assertHasNoActionErrors();

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeFalse()
            ->and(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeFalse()
            ->and(CashReceipt::query()->whereKey($receipt->id)->exists())->toBeTrue()
            ->and(CashReceipt::query()->findOrFail($receipt->id)->invoice_payment_id)->toBeNull();
    });
});
