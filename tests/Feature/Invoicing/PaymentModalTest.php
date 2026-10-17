<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
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

/**
 * The notifications sent so far, flushed from the mailbox.
 *
 * @return list<array{title: string, body: string, status: string}>
 */
function paymentNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $panel = new Notifications;
    $panel->mount();
    $panel->pullNotificationsFromSession();

    $sent = [];

    foreach ($panel->notifications as $notification) {
        $sent[] = ['title' => (string) $notification->getTitle(), 'body' => (string) $notification->getBody(), 'status' => (string) $notification->getStatus()];
    }

    return $sent;
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
