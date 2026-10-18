<?php

declare(strict_types=1);

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
