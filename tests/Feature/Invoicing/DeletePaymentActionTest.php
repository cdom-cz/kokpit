<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The deletion of a mistaken payment from the Platby tab (IN-05, IN-06, FI-03; D-19 as amended on 2026-10-17; rulings
 * R-19-1, R-41-1, R-41-2; UI-SPEC Surface F): the confirmation, the income that goes with the payment, the derived
 * state that follows, the carried proforma payment and the forged calls. Every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

function paymentDeletionTab(Invoice $invoice): Testable
{
    return Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class]);
}

function paymentDeletionPage(Invoice $invoice): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

function rowDeletionPay(Invoice $invoice, string $amount, string $paidOn = '2026-10-14'): InvoicePayment
{
    return app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => $paidOn]);
}

describe('an invoice payment', function (): void {
    it('is deleted with its income after one confirmation and the badge follows', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = rowDeletionPay($invoice, '1000.00');

        expect(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeTrue();

        $tab = paymentDeletionTab($invoice)
            ->assertTableActionExists('deletePayment', null, $payment)
            ->mountAction(TestAction::make('deletePayment')->table($payment))
            ->assertMountedActionModalSee([
                'Smazat platbu?',
                Money::ofMinor(100000, 'CZK')->format('cs'),
                '14. 10. 2026',
                'spolu s příjmem, který z ní vznikl ve financích',
                'Stav úhrady dokladu se přepočítá ze zbývajících plateb.',
                'Ponechat platbu',
            ]);

        expect($tab->instance()->getMountedAction()?->getColor())->toBe('danger');

        $tab->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertDispatched('invoice-payment-saved')
            ->assertNotified('Platba byla smazána');

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeFalse()
            ->and(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeFalse();

        paymentDeletionPage($invoice)->assertSee('Nezaplaceno')->assertDontSee('Zaplaceno')->assertActionVisible('recordPayment');
    });

    it('turns an overdue invoice back to Po splatnosti with its banner', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = rowDeletionPay($invoice, '1000.00');

        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));

        paymentDeletionPage($invoice)->assertDontSee('Faktura je po splatnosti o');

        paymentDeletionTab($invoice)->callAction(TestAction::make('deletePayment')->table($payment))->assertHasNoActionErrors();

        paymentDeletionPage($invoice)->assertSee('Faktura je po splatnosti o')->assertSee('Po splatnosti');
    });

    it('leaves a partly paid invoice when one of two payments is deleted (R-41-1)', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $first = rowDeletionPay($invoice, '400.00');
        $second = rowDeletionPay($invoice, '600.00');

        paymentDeletionPage($invoice)->assertSee('Zaplaceno');

        paymentDeletionTab($invoice)->callAction(TestAction::make('deletePayment')->table($second))->assertHasNoActionErrors();

        paymentDeletionPage($invoice)->assertSee('Částečně zaplaceno');

        expect($invoice->payments()->pluck('id')->all())->toBe([$first->id])
            ->and(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
    });

    it('keeps the payment when the confirmation is cancelled', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = rowDeletionPay($invoice, '1000.00');

        paymentDeletionTab($invoice)
            ->mountAction(TestAction::make('deletePayment')->table($payment))
            ->unmountAction();

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue();
    });

    it('is offered on the payment of a cancelled invoice and has no edit action anywhere', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = rowDeletionPay($invoice, '1000.00');
        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        paymentDeletionTab($invoice)
            ->assertTableActionVisible('deletePayment', $payment)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('update');
    });
});

describe('a proforma payment', function (): void {
    it('says in the confirmation that no income exists and deletes the payment only', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
        $payment = rowDeletionPay($proforma, '1000.00');

        $tab = paymentDeletionTab($proforma)
            ->mountAction(TestAction::make('deletePayment')->table($payment))
            ->assertMountedActionModalSee(['Smazat platbu?', 'U zálohy z ní žádný příjem nevznikl.'])
            ->assertMountedActionModalDontSee('spolu s příjmem');

        $tab->callMountedAction()->assertHasNoActionErrors()->assertNotified('Platba byla smazána');

        expect($proforma->payments()->count())->toBe(0);

        paymentDeletionPage($proforma)->assertDontSee('Záloha je zaplacená');
    });

    it('is disabled with the invoice number once it is carried to an invoice, and a forged call changes nothing (R-41-2)', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
        $payment = rowDeletionPay($proforma, '1000.00');
        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        $tab = paymentDeletionTab($proforma)
            ->assertTableActionDisabled('deletePayment', $payment)
            ->assertSee('Platba je přenesená na fakturu '.$invoice->number.'. Nejdřív smažte platbu na faktuře.');

        $tab->callAction(TestAction::make('deletePayment')->table($payment));

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and($invoice->payments()->count())->toBe(1)
            ->and(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(1);

        // The payment on the invoice is deletable, and then the proforma payment is as well.
        $copy = $invoice->payments()->sole();

        Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])
            ->assertTableActionEnabled('deletePayment', $copy)
            ->callAction(TestAction::make('deletePayment')->table($copy))
            ->assertHasNoActionErrors();

        paymentDeletionTab($proforma)
            ->assertTableActionEnabled('deletePayment', $payment)
            ->callAction(TestAction::make('deletePayment')->table($payment))
            ->assertHasNoActionErrors();

        expect($proforma->payments()->count())->toBe(0);
    });
});

describe('a refusal and a forged call', function (): void {
    it('shows the Czech message of a refusal of DeletePayment as a danger notification and keeps the rows', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = rowDeletionPay($invoice, '1000.00');

        app()->bind(DeletePayment::class, static fn (): object => new class
        {
            public function handle(): never
            {
                throw ValidationException::withMessages(['payment' => __('invoices.errors.payment_missing')]);
            }
        });

        paymentDeletionTab($invoice)
            ->callAction(TestAction::make('deletePayment')->table($payment))
            ->assertNotified(__('invoices.errors.payment_missing'));

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue();
    });

    it('disables the delete of a cash payment with a valid receipt, names the receipt, and keeps the cash payment, its income and the receipt', function (): void {
        Queue::fake();
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => '1000.00', 'paid_on' => '2026-10-14', 'method' => 'cash']);
        $receipt = CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();

        // A disabled action is not run (R-15-14-3); the refusal of the Action itself is proven in CashReceiptCancelTest.
        paymentDeletionTab($invoice)
            ->assertTableActionDisabled('deletePayment', $payment)
            ->assertSee(__('cash_receipts.admin.delete_blocked', ['number' => $receipt->number]))
            ->callAction(TestAction::make('deletePayment')->table($payment));

        expect(InvoicePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
            ->and(Transaction::query()->where('invoice_payment_id', $payment->id)->exists())->toBeTrue()
            ->and(CashReceipt::query()->whereKey($receipt->id)->value('invoice_payment_id'))->toBe($payment->id);
    });

    it('does not find a payment of another invoice through the host invoice', function (): void {
        $client = InvoiceFixtures::client();
        $host = InvoiceFixtures::issued($client);
        rowDeletionPay($host, '300.00');
        $other = InvoiceFixtures::issued($client);
        $foreign = rowDeletionPay($other, '500.00');

        try {
            paymentDeletionTab($host)->callAction(TestAction::make('deletePayment')->table($foreign));
        } catch (Throwable) {
            // The tab does not list the payment, so the table cannot resolve the record; either way nothing happens.
        }

        expect(InvoicePayment::query()->whereKey($foreign->id)->exists())->toBeTrue()
            ->and(Transaction::query()->where('invoice_payment_id', $foreign->id)->exists())->toBeTrue()
            ->and($host->payments()->count())->toBe(1);
    });

    it('answers a Partner with 403 on the page and on the tab', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $payment = rowDeletionPay($invoice, '1000.00');

        $this->actingAs(Canary::partnerFor($client->id));

        paymentDeletionPage($invoice)->assertForbidden();
        paymentDeletionTab($invoice)->assertForbidden();

        expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => InvoicePayment::query()->whereKey($payment->id)->exists()))->toBeTrue();
    });
});
