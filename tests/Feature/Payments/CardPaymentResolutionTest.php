<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Queries\UnresolvedPayments;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\CardPaymentResource\Pages\ViewCardPayment;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentFixtures;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * The Admin's three resolutions of a card payment that needs a decision (PA-08; D-13, D-14; UI-SPEC Surface H, U-11,
 * U-12): assign it to a document, mark it resolved with a note, record the money return as an expense. Every name and
 * amount is fictional; Stripe references are assembled at runtime by the fixtures.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    Queue::fake();
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * The record page of one card payment, opened by the test's Admin (the document fixtures act as an Admin of their own).
 */
function resolutionPage(CardPayment $record): Testable
{
    test()->actingAs(test()->admin);

    return Livewire::test(ViewCardPayment::class, ['record' => $record->getRouteKey()]);
}

/**
 * The text of the mounted modal's form (the page html does not carry the modal of an action).
 */
function modalHtml(Testable $page): string
{
    return html_entity_decode(strip_tags((string) $page->instance()->getSchema('mountedActionSchema0')->toHtml()), ENT_QUOTES | ENT_HTML5);
}

/**
 * The record as the database holds it now.
 */
function storedRecord(CardPayment $record): CardPayment
{
    return app(PartnerContext::class)->runAsSystem(static fn (): CardPayment => CardPayment::query()->findOrFail($record->id));
}

describe('the tracer: assign an unmatched payment to its invoice', function (): void {
    it('books the payment and its income, resolves the record and takes it out of the queue', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(), amount: '12000.00');
        $record = CardPaymentFixtures::unmatched($invoice, ['amount_minor' => 1200000]);
        $before = app(UnresolvedPayments::class)->counts()['total'];

        resolutionPage($record)
            ->assertActionVisible('assignCardPayment')
            ->mountAction('assignCardPayment')
            ->assertSchemaStateSet(['invoice_id' => $invoice->id, 'paid_on' => '2026-10-14', 'income_date' => '2026-10-14'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertDispatched('card-payment-resolved')
            ->assertNotified('Platba byla přiřazena k dokladu '.$invoice->number)
            ->assertActionHidden('assignCardPayment')
            ->assertSee('Přiřazeno k dokladu '.$invoice->number);

        $payment = $invoice->payments()->sole();
        $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();
        $stored = storedRecord($record);

        expect($payment->method)->toBe(PaymentMethod::Card)
            ->and($payment->created_by)->toBe($this->admin->id)
            ->and($payment->amount_minor)->toBe(1200000)
            ->and($payment->paid_on->toDateString())->toBe('2026-10-14')
            ->and($income->category_id)->toBe(TransactionCategory::invoicePayments()->getKey())
            ->and($stored->resolution)->toBe(CardPaymentResolution::Assigned)
            ->and($stored->assigned_invoice_id)->toBe($invoice->id)
            ->and($stored->invoice_payment_id)->toBe($payment->id)
            ->and($stored->resolved_by)->toBe($this->admin->id)
            ->and($stored->resolved_at)->not->toBeNull()
            ->and(app(UnresolvedPayments::class)->counts()['total'])->toBe($before - 1);
    });
});

describe('mark as resolved with a note', function (): void {
    it('closes a duplicate, an unmatched payment and a refund event with the note and moves no money', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $paid = $invoice->payments()->count();
        $incomes = Transaction::query()->count();

        foreach ([CardPaymentFixtures::duplicate($invoice), CardPaymentFixtures::unmatched(), CardPaymentFixtures::refund($invoice)] as $record) {
            resolutionPage($record)
                ->assertActionVisible('resolveCardPayment')
                ->mountAction('resolveCardPayment')
                ->fillForm(['note' => '  Vráceno ve Stripe dashboardu.  '])
                ->callMountedAction()
                ->assertHasNoFormErrors()
                ->assertDispatched('card-payment-resolved')
                ->assertNotified('Platba byla označena jako vyřešená')
                ->assertActionHidden('resolveCardPayment');

            $stored = storedRecord($record);

            expect($stored->resolution)->toBe(CardPaymentResolution::Noted)
                ->and($stored->resolution_note)->toBe('Vráceno ve Stripe dashboardu.')
                ->and($stored->resolved_by)->toBe($this->admin->id)
                ->and($stored->invoice_payment_id)->toBeNull()
                ->and($stored->return_transaction_id)->toBeNull();
        }

        expect($invoice->payments()->count())->toBe($paid)
            ->and(Transaction::query()->count())->toBe($incomes);
    });

    it('refuses a blank note as a field error and leaves the record open', function (): void {
        $record = CardPaymentFixtures::unmatched();

        resolutionPage($record)
            ->mountAction('resolveCardPayment')
            ->fillForm(['note' => '   '])
            ->callMountedAction()
            ->assertHasFormErrors(['note']);

        expect(storedRecord($record)->resolution)->toBe(CardPaymentResolution::None);
    });

    it('paints the buttons by type: resolve in the accent on a duplicate and a refund, gray on an unmatched payment', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        resolutionPage(CardPaymentFixtures::duplicate($invoice))
            ->assertActionHasColor('resolveCardPayment', 'primary')
            ->assertActionHasColor('assignCardPayment', 'gray')
            ->assertActionHasColor('recordPaymentReturn', 'gray');
        resolutionPage(CardPaymentFixtures::refund($invoice))->assertActionHasColor('resolveCardPayment', 'primary');
        resolutionPage(CardPaymentFixtures::unmatched())
            ->assertActionHasColor('resolveCardPayment', 'gray')
            ->assertActionHasColor('assignCardPayment', 'primary');
    });
});

describe('record the money return', function (): void {
    it('books one expense linked from the duplicate, shows the info callout and calls no Stripe API', function (): void {
        $stripe = FakeStripeGateway::install();
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $record = CardPaymentFixtures::duplicate($invoice);
        $category = TransactionCategory::query()->selectable(TransactionType::Expense)->firstOrFail();
        $created = $stripe->createCalls();

        $page = resolutionPage($record)
            ->assertActionVisible('recordPaymentReturn')
            ->mountAction('recordPaymentReturn');

        expect(modalHtml($page))->toContain('Peníze vraťte ve Stripe, Kokpit žádné vrácení neprovádí.');

        $page
            ->assertSchemaStateSet(['amount' => '12500.00', 'returned_on' => '2026-10-14'], 'mountedActionSchema0')
            ->fillForm(['category_id' => $category->id, 'note' => 'Vráceno bankovním převodem.'])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertDispatched('card-payment-resolved')
            ->assertNotified('Vrácení bylo zaevidováno')
            ->assertActionHidden('recordPaymentReturn');

        $stored = storedRecord($record);
        $expense = Transaction::query()->where('type', TransactionType::Expense->value)->sole();

        expect($stored->resolution)->toBe(CardPaymentResolution::Returned)
            ->and($stored->return_transaction_id)->toBe($expense->id)
            ->and($stored->resolution_note)->toBe('Vráceno bankovním převodem.')
            ->and($expense->description)->toBe('Vrácení platby kartou')
            ->and($expense->amount->minor)->toBe(1250000)
            ->and($expense->amount->currency)->toBe('CZK')
            ->and($expense->category_id)->toBe($category->id)
            ->and($expense->client_id)->toBe($invoice->client_id)
            ->and($expense->transaction_date->toDateString())->toBe('2026-10-14')
            ->and($stripe->createCalls())->toBe($created)
            ->and($stripe->deactivated())->toBe([]);
    });

    it('refuses a return above the payment amount by one minor unit and writes nothing', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        $record = CardPaymentFixtures::duplicate($invoice);
        $category = TransactionCategory::query()->selectable(TransactionType::Expense)->firstOrFail();

        resolutionPage($record)
            ->mountAction('recordPaymentReturn')
            ->fillForm(['amount' => '12500,01', 'category_id' => $category->id])
            ->callMountedAction()
            ->assertHasFormErrors(['amount']);

        expect(storedRecord($record)->resolution)->toBe(CardPaymentResolution::None)
            ->and(Transaction::query()->where('type', TransactionType::Expense->value)->exists())->toBeFalse();
    });

    it('accepts a part of the amount', function (): void {
        $record = CardPaymentFixtures::unmatched();
        $category = TransactionCategory::query()->selectable(TransactionType::Expense)->firstOrFail();

        resolutionPage($record)
            ->mountAction('recordPaymentReturn')
            ->fillForm(['amount' => '20,50', 'category_id' => $category->id])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $expense = Transaction::query()->where('type', TransactionType::Expense->value)->sole();

        expect($expense->amount->minor)->toBe(2050)
            ->and($expense->client_id)->toBeNull();
    });
});

describe('what is offered', function (): void {
    it('offers a refund event only the note, a matched record nothing and a resolved record nothing', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        resolutionPage(CardPaymentFixtures::refund($invoice))
            ->assertActionHidden('assignCardPayment')
            ->assertActionHidden('recordPaymentReturn')
            ->assertActionVisible('resolveCardPayment');

        $matched = CardPaymentSetup::issued(CardPaymentSetup::client());

        resolutionPage(CardPaymentFixtures::matched($matched))
            ->assertActionHidden('assignCardPayment')
            ->assertActionHidden('resolveCardPayment')
            ->assertActionHidden('recordPaymentReturn');

        resolutionPage(CardPaymentFixtures::resolved())
            ->assertActionHidden('assignCardPayment')
            ->assertActionHidden('resolveCardPayment')
            ->assertActionHidden('recordPaymentReturn');
    });
});
