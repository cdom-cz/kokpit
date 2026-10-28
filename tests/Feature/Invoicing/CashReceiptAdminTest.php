<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Support\CashReceiptAudit;
use App\Domain\Invoicing\Support\CashReceiptPdfUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Pipeline\Pipeline;
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
function cashReceiptAdminPay(InvoiceKind $kind = InvoiceKind::Invoice, ?string $email = 'default', string $amount = '1500'): array
{
    $address = $email === 'default' ? exampleEmail('client-invoice') : $email;
    $client = InvoiceFixtures::client(['name' => 'Example Client s.r.o.', 'invoice_email' => $address]);
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')], $kind);

    $payment = app(RecordPayment::class)->handle(test()->admin, $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14', 'method' => 'cash']);
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

/**
 * The confirmation e-mail row of a payment, read as the system.
 */
function cashReceiptAdminConfirmation(InvoicePayment $payment): PaymentConfirmation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->sole());
}

/**
 * One try of the sending job through its own middleware: the successful send locks the receipt.
 */
function cashReceiptAdminSend(InvoicePayment $payment): void
{
    $job = new SendPaymentConfirmation(cashReceiptAdminConfirmation($payment)->id);

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (SendPaymentConfirmation $job): mixed => app()->call($job->handle(...)));
}

function cashReceiptAdminFresh(CashReceipt $receipt): CashReceipt
{
    return CashReceipt::query()->findOrFail($receipt->id);
}

/**
 * The data of the edit dialog: what the dialog is prefilled with, with the given keys replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function cashReceiptAdminForm(CashReceipt $receipt, array $override = []): array
{
    $party = static fn (array $snapshot): array => [
        'name' => $snapshot['name'] ?? null,
        'street' => $snapshot['street'] ?? null,
        'city' => $snapshot['city'] ?? null,
        'postal_code' => $snapshot['postal_code'] ?? null,
        'country' => $snapshot['country'] ?? null,
        'company_id' => $snapshot['company_id'] ?? null,
        'vat_id' => $snapshot['vat_id'] ?? null,
    ];

    return array_replace_recursive([
        'received_on' => $receipt->received_on->format('Y-m-d'),
        'purpose' => $receipt->purpose,
        'customer' => $party($receipt->customer_snapshot),
        'supplier' => $party($receipt->supplier_snapshot),
    ], $override);
}

describe('the edit of an open receipt (D-10)', function (): void {
    it('prefills the dialog, saves a new purpose and a new customer name, and the stored PDF follows', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();
        $oldPdf = $receipt->pdf_document_id;

        cashReceiptAdminTab($invoice)
            ->assertTableActionVisible('editCashReceipt', $payment)
            ->mountAction(TestAction::make('editCashReceipt')->table($payment))
            ->assertActionDataSet(['purpose' => $receipt->purpose, 'received_on' => '2026-10-14'])
            ->unmountAction();

        cashReceiptAdminTab($invoice)
            ->callAction(TestAction::make('editCashReceipt')->table($payment), cashReceiptAdminForm($receipt, [
                'purpose' => 'Úhrada v hotovosti na pobočce',
                'customer' => ['name' => 'Example Buyer s.r.o.'],
            ]))
            ->assertHasNoActionErrors()
            ->assertDispatched('invoice-payment-saved')
            ->assertNotified(__('cash_receipts.admin.edit.done'));

        $row = cashReceiptAdminFresh($receipt);

        expect($row->purpose)->toBe('Úhrada v hotovosti na pobočce')
            ->and($row->customer_snapshot['name'] ?? null)->toBe('Example Buyer s.r.o.')
            ->and($row->amount_minor)->toBe(150000)
            ->and($row->pdf_document_id)->not->toBeNull()
            ->and($row->pdf_document_id)->not->toBe($oldPdf);
    });

    it('shows a refused value under its field and keeps the receipt', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();

        cashReceiptAdminTab($invoice)
            ->callAction(TestAction::make('editCashReceipt')->table($payment), cashReceiptAdminForm($receipt, ['received_on' => '2026-10-15']))
            ->assertHasActionErrors(['received_on']);

        expect(cashReceiptAdminFresh($receipt)->received_on->format('Y-m-d'))->toBe('2026-10-14');
    });

    it('hides the edit on a locked receipt and on a cancelled one', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();

        cashReceiptAdminSend($payment);

        expect(cashReceiptAdminFresh($receipt)->lock_state)->toBe(CashReceiptLockState::Locked);

        cashReceiptAdminTab($invoice)
            ->assertTableActionHidden('editCashReceipt', $payment)
            ->assertSee('Uzamčený');

        [$otherInvoice, $otherPayment] = cashReceiptAdminPay();

        cashReceiptAdminTab($otherInvoice)
            ->assertTableActionVisible('editCashReceipt', $otherPayment)
            ->callAction(TestAction::make('cancelCashReceipt')->table($otherPayment), ['reason' => 'Chybně zadaná platba v hotovosti'])
            ->assertTableActionHidden('editCashReceipt', $otherPayment);
    });
});

describe('the unlock of a locked receipt (D-10)', function (): void {
    it('offers the unlock only once the send locked the receipt, asks for a reason of 10 characters, and opens the receipt for the edit', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();

        cashReceiptAdminTab($invoice)->assertTableActionHidden('unlockCashReceipt', $payment);

        cashReceiptAdminSend($payment);

        $tab = cashReceiptAdminTab($invoice)
            ->assertTableActionVisible('unlockCashReceipt', $payment)
            ->assertTableActionHidden('editCashReceipt', $payment)
            ->callAction(TestAction::make('unlockCashReceipt')->table($payment), ['reason' => 'Oprava'])
            ->assertHasActionErrors(['reason'])
            ->unmountAction();

        expect(cashReceiptAdminFresh($receipt)->lock_state)->toBe(CashReceiptLockState::Locked);

        $tab->callAction(TestAction::make('unlockCashReceipt')->table($payment), ['reason' => 'Oprava jména plátce po odeslání'])
            ->assertHasNoActionErrors()
            ->assertDispatched('invoice-payment-saved')
            ->assertNotified(__('cash_receipts.admin.unlock.done'));

        expect(cashReceiptAdminFresh($receipt)->lock_state)->toBe(CashReceiptLockState::Open);

        $tab->assertTableActionVisible('editCashReceipt', $payment)
            ->assertTableActionHidden('unlockCashReceipt', $payment)
            ->assertSee('Otevřený');
    });
});

describe('the manual lock of an open receipt (Phase 16, D-02, D-03)', function (): void {
    it('locks an open receipt from the row, audits it once with the Admin, changes nothing else, and then offers the unlock', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();
        $before = cashReceiptAdminFresh($receipt);

        cashReceiptAdminTab($invoice)
            ->assertTableActionVisible('lockCashReceipt', $payment)
            ->assertTableActionHidden('unlockCashReceipt', $payment)
            ->callAction(TestAction::make('lockCashReceipt')->table($payment))
            ->assertHasNoActionErrors()
            ->assertDispatched('invoice-payment-saved')
            ->assertNotified(__('cash_receipts.admin.lock.done'))
            ->assertTableActionHidden('lockCashReceipt', $payment)
            ->assertTableActionHidden('editCashReceipt', $payment)
            ->assertTableActionVisible('unlockCashReceipt', $payment)
            ->assertSee('Uzamčený');

        $row = cashReceiptAdminFresh($receipt);

        expect($row->lock_state)->toBe(CashReceiptLockState::Locked)
            ->and($row->number)->toBe($before->number)
            ->and($row->amount_minor)->toBe($before->amount_minor)
            ->and($row->currency)->toBe($before->currency)
            ->and($row->supplier_snapshot)->toBe($before->supplier_snapshot)
            ->and($row->customer_snapshot)->toBe($before->customer_snapshot);

        $rows = Activity::query()
            ->where('subject_type', 'cash_receipt')
            ->where('subject_id', $receipt->id)
            ->where('event', 'cash_receipt_locked_manually')
            ->get();

        expect($rows)->toHaveCount(1)
            ->and($rows->first()?->causer_id)->toBe($this->admin->id)
            ->and($rows->first()?->attribute_changes?->get('old'))->toMatchArray(['lock_state' => 'open'])
            ->and($rows->first()?->attribute_changes?->get('attributes'))->toMatchArray(['lock_state' => 'locked']);
    });

    it('offers no lock on a cancelled receipt, on a carried copy, or on a payment by transfer', function (): void {
        [$invoice, $payment] = cashReceiptAdminPay();

        cashReceiptAdminTab($invoice)
            ->callAction(TestAction::make('cancelCashReceipt')->table($payment), ['reason' => 'Chybně zadaná platba v hotovosti'])
            ->assertTableActionHidden('lockCashReceipt', $payment);

        [$proforma] = cashReceiptAdminPay(InvoiceKind::Proforma, 'default', '12500');
        $carried = app(ConvertProforma::class)->handle($this->admin, $proforma);

        cashReceiptAdminTab($carried)->assertTableActionHidden('lockCashReceipt', $carried->payments()->sole());

        $transfer = InvoiceFixtures::issued(InvoiceFixtures::client());
        $transferPayment = app(RecordPayment::class)->handle($this->admin, $transfer, ['amount' => '1000.00', 'paid_on' => '2026-10-14']);

        cashReceiptAdminTab($transfer)->assertTableActionHidden('lockCashReceipt', $transferPayment);
    });
});

describe('the PDF of a receipt (AS-12)', function (): void {
    it('offers the recreation only when the PDF is missing and links a new one', function (): void {
        [$invoice, $payment, $receipt] = cashReceiptAdminPay();

        cashReceiptAdminTab($invoice)
            ->assertTableActionHidden('regenerateCashReceiptPdf', $payment)
            ->assertTableActionVisible('downloadCashReceipt', $payment);

        app(DeleteDocument::class)->handle($this->admin, Document::query()->findOrFail($receipt->pdf_document_id), true);

        expect(cashReceiptAdminFresh($receipt)->pdf_document_id)->toBeNull();

        $tab = cashReceiptAdminTab($invoice)
            ->assertTableActionVisible('regenerateCashReceiptPdf', $payment)
            ->assertTableActionHidden('downloadCashReceipt', $payment)
            ->callAction(TestAction::make('regenerateCashReceiptPdf')->table($payment))
            ->assertHasNoActionErrors()
            ->assertNotified(__('cash_receipts.admin.regenerate.done'));

        expect(cashReceiptAdminFresh($receipt)->pdf_document_id)->not->toBeNull();

        $tab->assertTableActionHidden('regenerateCashReceiptPdf', $payment)
            ->assertTableActionVisible('downloadCashReceipt', $payment);
    });
});

describe('what the tab shows for the other cases', function (): void {
    it('shows the receipt of the proforma on the carried payment of the invoice and offers only the download', function (): void {
        [$proforma, , $receipt] = cashReceiptAdminPay(InvoiceKind::Proforma, 'default', '12500');
        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $copy = $invoice->payments()->sole();

        cashReceiptAdminTab($invoice)
            ->assertSee($receipt->number)
            ->assertSee('ze zálohové faktury')
            ->assertSee('Otevřený')
            ->assertTableActionVisible('downloadCashReceipt', $copy)
            ->assertTableActionHidden('editCashReceipt', $copy)
            ->assertTableActionHidden('cancelCashReceipt', $copy)
            ->assertTableActionHidden('unlockCashReceipt', $copy)
            ->assertTableActionHidden('regenerateCashReceiptPdf', $copy)
            ->assertTableActionEnabled('deletePayment', $copy);

        expect(CashReceipt::query()->count())->toBe(1);
    });

    it('shows a receipt whose confirmation was skipped as open, not hidden and not sent (AS-10)', function (): void {
        [$invoice, , $receipt] = cashReceiptAdminPay(InvoiceKind::Invoice, null);

        cashReceiptAdminTab($invoice)
            ->assertSee($receipt->number)
            ->assertSee('Otevřený')
            ->assertSee('Bez e-mailu')
            ->assertDontSee('Uzamčený');

        expect(cashReceiptAdminFresh($receipt)->lock_state)->toBe(CashReceiptLockState::Open);
    });

    it('offers no receipt action on a payment by transfer', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '1000.00', 'paid_on' => '2026-10-14']);

        cashReceiptAdminTab($invoice)
            ->assertTableActionHidden('downloadCashReceipt', $payment)
            ->assertTableActionHidden('cancelCashReceipt', $payment)
            ->assertTableActionEnabled('deletePayment', $payment);
    });
});

describe('who and what a forged call reaches (T-15-38, T-15-39)', function (): void {
    it('answers a Partner with 403 on the tab', function (): void {
        [$invoice, , $receipt] = cashReceiptAdminPay();

        $this->actingAs(Canary::partnerFor($receipt->client_id));

        cashReceiptAdminTab($invoice)->assertForbidden();
    });

    it('does not reach the receipt of a payment of another invoice through the host invoice', function (): void {
        [$host] = cashReceiptAdminPay();
        [, $foreignPayment, $foreignReceipt] = cashReceiptAdminPay();

        try {
            cashReceiptAdminTab($host)->callAction(TestAction::make('cancelCashReceipt')->table($foreignPayment), ['reason' => 'Chybně zadaná platba v hotovosti']);
        } catch (Throwable) {
            // The tab does not list the payment, so the table cannot resolve the record; either way nothing happens.
        }

        expect(cashReceiptAdminFresh($foreignReceipt)->cancelled_at)->toBeNull();
    });
});
