<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The payment modal of the document detail and of its Platby tab (IN-05, FI-03; D-17, D-18, D-19; UI-SPEC Surface F,
 * N7): the defaults, the live warnings, the proforma variant and the refusals. Every name, rate and amount is
 * fictional.
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

/**
 * The page of an issued document.
 */
function paymentPage(Invoice $invoice): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The Platby tab of an issued document.
 */
function paymentTab(Invoice $invoice): Testable
{
    return Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class]);
}

describe('the tracer: the full payment of an issued invoice from the detail', function (): void {
    it('records it in two clicks, turns the badge to Zaplaceno and books the income', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        $page = paymentPage($invoice)
            ->assertSee('Nezaplaceno')
            ->assertActionVisible('recordPayment')
            ->mountAction('recordPayment')
            ->assertSchemaStateSet(['amount' => '1000.00', 'paid_on' => '2026-10-14', 'method' => PaymentMethod::Transfer, 'income_date' => '2026-10-14', 'category_id' => TransactionCategory::invoicePayments()->getKey()], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertDispatched('invoice-payment-saved')
            ->assertNotified('Platba byla uložena')
            ->assertSee('Zaplaceno')
            ->assertDontSee('Nezaplaceno')
            ->assertActionHidden('recordPayment');

        $payment = $invoice->payments()->sole();
        $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

        expect($payment->amount_minor)->toBe(100000)
            ->and($payment->paid_on->toDateString())->toBe('2026-10-14')
            ->and($income->category_id)->toBe(TransactionCategory::invoicePayments()->getKey())
            ->and($income->transaction_date->toDateString())->toBe('2026-10-14');

        expect($page->html())->not->toContain('Zbývá uhradit 1');
    });

    it('removes the overdue banner when the remaining amount reaches zero', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));

        paymentPage($invoice)
            ->assertSee('Faktura je po splatnosti o')
            ->assertSee('Po splatnosti')
            ->mountAction('recordPayment')
            ->callMountedAction()
            ->assertDontSee('Faktura je po splatnosti o')
            ->assertSee('Zaplaceno');
    });

    it('does not offer the header action on a paid invoice, a credit note or a cancelled invoice', function (): void {
        $client = InvoiceFixtures::client();

        $paid = InvoiceFixtures::issued($client);
        paymentPage($paid)->assertActionVisible('recordPayment')->mountAction('recordPayment')->callMountedAction();
        paymentPage($paid)->assertActionHidden('recordPayment');

        $cancelled = InvoiceFixtures::issued($client);
        $credit = app(IssueCreditNote::class)->storno($this->admin, $cancelled);

        paymentPage($cancelled)->assertActionHidden('recordPayment');
        paymentPage($credit)->assertActionHidden('recordPayment');
        expect($credit->kind)->toBe(InvoiceKind::CreditNote);
    });

    it('answers a Partner with 403 on the page', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        paymentPage($invoice)->assertForbidden();
    });
});

/**
 * Opens the payment modal of the Platby tab.
 */
function paymentTabModal(Invoice $invoice): Testable
{
    return paymentTab($invoice)->mountAction(TestAction::make('recordPayment')->table());
}

describe('partial payments (UI-SPEC N7)', function (): void {
    it('moves the badge through zero, one and several payments', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        paymentPage($invoice)->assertSee('Nezaplaceno')->assertSee(Money::ofMinor(100000, 'CZK')->format('cs'));

        paymentTab($invoice)
            ->callAction(TestAction::make('recordPayment')->table(), ['amount' => '400,00', 'paid_on' => '2026-10-13', 'method' => 'cash', 'note' => 'First part'])
            ->assertHasNoActionErrors()
            ->assertDispatched('invoice-payment-saved')
            ->assertNotified('Platba byla uložena');

        paymentPage($invoice)
            ->assertSee('Částečně zaplaceno')
            ->assertSee(Money::ofMinor(40000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(60000, 'CZK')->format('cs'))
            ->assertActionVisible('recordPayment');

        paymentTabModal($invoice)
            ->assertSchemaStateSet(['amount' => '600.00'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        paymentPage($invoice)->assertSee('Zaplaceno')->assertDontSee('Částečně zaplaceno')->assertActionHidden('recordPayment');

        expect($invoice->payments()->count())->toBe(2)
            ->and(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
    });

    it('keeps the action in the tab of a paid invoice and warns that it is already paid', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        paymentTabModal($invoice)->callMountedAction();

        $modal = paymentTabModal($invoice)
            ->assertSchemaStateSet(['amount' => null], 'mountedActionSchema0')
            ->assertMountedActionModalSee(['Faktura je již zaplacená.', 'Přesto uložit platbu']);

        $modal->fillForm(['amount' => '100,00'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Platba byla uložena');

        expect($invoice->payments()->count())->toBe(2);
    });

    it('warns live when the amount is above the remaining amount and still saves it', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        $modal = paymentTabModal($invoice)
            ->assertMountedActionModalDontSee('Zadaná částka je vyšší než zbývající částka')
            ->assertMountedActionModalSee('Uložit platbu')
            ->assertMountedActionModalDontSee('Přesto uložit platbu')
            ->fillForm(['amount' => '1 500,00'], 'mountedActionSchema0')
            ->assertMountedActionModalSee(['Zadaná částka je vyšší než zbývající částka ('.Money::ofMinor(100000, 'CZK')->format('cs').').', 'Přesto uložit platbu'])
            ->assertMountedActionModalDontSee('Faktura je již zaplacená.');

        $modal->callMountedAction()->assertHasNoActionErrors()->assertNotified('Platba byla uložena');

        expect($invoice->payments()->sole()->amount_minor)->toBe(150000);
    });

    it('shows the note that a stored payment can only be deleted, and the helper of the tab, and no edit action', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        paymentTabModal($invoice)->callMountedAction();
        $payment = $invoice->payments()->sole();

        paymentTab($invoice)
            ->assertSee('Uloženou platbu nelze upravit. Chybnou platbu smažte akcí Smazat platbu; smaže se i příjem, který z ní vznikl.')
            ->assertTableActionExists('deletePayment')
            ->assertTableActionDoesNotExist('edit')
            ->mountAction(TestAction::make('recordPayment')->table())
            ->assertMountedActionModalSee('Uloženou platbu nelze upravit, jen smazat.');

        expect(paymentTab($invoice)->html())->not->toContain('Upravit platbu');
        expect($payment->id)->not->toBeEmpty();
    });
});

describe('the fields of the modal', function (): void {
    it('hides the income date and category on a proforma, explains why and stores the payment only', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);

        paymentTabModal($proforma)
            ->assertMountedActionModalSee('Platba se u zálohy jen eviduje. Příjem se zaúčtuje až při vystavení faktury.')
            ->assertSchemaComponentHidden('income_date', 'mountedActionSchema0')
            ->assertSchemaComponentHidden('category_id', 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Platba u zálohy byla uložena');

        expect($proforma->payments()->count())->toBe(1)
            ->and(Transaction::query()->where('invoice_id', $proforma->id)->count())->toBe(0);

        paymentPage($proforma)->assertSee('Záloha je zaplacená')->assertActionVisible('convertProforma');
    });

    it('shows the income fields on an invoice and no proforma callout', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        paymentTabModal($invoice)
            ->assertMountedActionModalDontSee('Platba se u zálohy jen eviduje.')
            ->assertSchemaComponentVisible('income_date', 'mountedActionSchema0')
            ->assertSchemaComponentVisible('category_id', 'mountedActionSchema0');
    });

    it('refuses a payment date in the future under the field and stores nothing', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        paymentTab($invoice)
            ->callAction(TestAction::make('recordPayment')->table(), ['amount' => '100,00', 'paid_on' => '2026-10-20'])
            ->assertHasActionErrors(['paid_on']);

        expect($invoice->payments()->count())->toBe(0);
    });

    it('refuses a zero amount under the amount', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        paymentTab($invoice)
            ->callAction(TestAction::make('recordPayment')->table(), ['amount' => '0', 'paid_on' => '2026-10-14'])
            ->assertHasActionErrors(['amount']);

        expect($invoice->payments()->count())->toBe(0);
    });

    it('passes the income date and another income category to the Action', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $other = TransactionCategory::query()->where('type', 'income')->whereNull('parent_id')->where('is_system', false)->firstOrFail();

        paymentTab($invoice)
            ->callAction(TestAction::make('recordPayment')->table(), [
                'amount' => '1000,00',
                'paid_on' => '2026-10-13',
                'income_date' => '2026-10-11',
                'category_id' => $other->id,
            ])
            ->assertHasNoActionErrors();

        $payment = $invoice->payments()->sole();
        $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

        expect($payment->paid_on->toDateString())->toBe('2026-10-13')
            ->and($income->transaction_date->toDateString())->toBe('2026-10-11')
            ->and($income->category_id)->toBe($other->id);
    });

    it('offers no payment action in the tab of a cancelled invoice and answers a Partner with 403', function (): void {
        $client = InvoiceFixtures::client();
        $cancelled = InvoiceFixtures::issued($client);
        app(IssueCreditNote::class)->storno($this->admin, $cancelled);

        paymentTab($cancelled)->assertActionHidden(TestAction::make('recordPayment')->table());

        $this->actingAs(Canary::partnerFor($client->id));

        paymentTab($cancelled)->assertForbidden();
    });

    it('offers no payment action on a converted proforma', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-14']);
        app(ConvertProforma::class)->handle($this->admin, $proforma);

        paymentTab($proforma)->assertActionHidden(TestAction::make('recordPayment')->table());
    });
});
