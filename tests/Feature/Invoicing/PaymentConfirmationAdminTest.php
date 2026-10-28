<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * What the Admin sees of the payment confirmation (Phase 13, D-05; A-13-3, A-13-7; R-13-08-3, R-13-08-4): the hint of
 * the record dialog says whom the client e-mail will reach, and the Platby tab shows its state per payment. Every
 * name and address is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    Mail::getSymfonyTransport()->flush();
});

/**
 * The Platby tab of an issued document.
 */
function payAdminTab(Invoice $invoice): Testable
{
    return Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class]);
}

/**
 * The record dialog opened from the Platby tab.
 */
function payAdminDialog(Invoice $invoice): Testable
{
    return payAdminTab($invoice)->mountAction(TestAction::make('recordPayment')->table());
}

describe('the tracer: the dialog tells whom the client e-mail reaches, the tab shows that it went out', function (): void {
    it('names the invoice e-mail in the dialog, then lists the confirmation as sent', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $address]));
        Mail::getSymfonyTransport()->flush();

        payAdminDialog($invoice)
            ->assertMountedActionModalSee('Klientovi odejde na '.$address.' potvrzení přijetí platby.')
            ->assertMountedActionModalDontSee('Klient nemá použitelný fakturační e-mail')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Platba byla uložena');

        expect(MailFixtures::to($address))->toHaveCount(1);

        payAdminTab($invoice)
            ->assertSee('Potvrzení klientovi')
            ->assertSee('Odesláno')
            ->assertDontSee($address);
    });

    it('says that nothing is sent when the client has no invoice e-mail, saves anyway and lists "Bez e-mailu"', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => null]));
        Mail::getSymfonyTransport()->flush();

        payAdminDialog($invoice)
            ->assertMountedActionModalSee('Klient nemá použitelný fakturační e-mail, potvrzení platby se neodešle.')
            ->assertMountedActionModalDontSee('Klientovi odejde na')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Platba byla uložena');

        expect($invoice->payments()->count())->toBe(1)
            ->and(MailFixtures::sent())->toBe([]);

        payAdminTab($invoice)->assertSee('Bez e-mailu');
    });

    it('says that nothing is sent when the client has document e-mails switched off, and saves the payment anyway (Phase 16, D-06)', function (): void {
        $address = exampleEmail('client-invoice');
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => $address, 'document_emails_enabled' => false]));
        Mail::getSymfonyTransport()->flush();

        payAdminDialog($invoice)
            ->assertMountedActionModalSee('Klient má odesílání dokladů e-mailem vypnuté, potvrzení platby se neodešle.')
            ->assertMountedActionModalDontSee('Klientovi odejde na')
            ->assertMountedActionModalDontSee('Klient nemá použitelný fakturační e-mail')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Platba byla uložena');

        expect($invoice->payments()->count())->toBe(1)
            ->and(MailFixtures::sent())->toBe([]);

        payAdminTab($invoice)->assertSee('Bez e-mailu');
    });

    it('treats an unusable stored address like none', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => 'not an address']));

        payAdminDialog($invoice)->assertMountedActionModalSee('Klient nemá použitelný fakturační e-mail');
    });
});

describe('the confirmation column', function (): void {
    it('shows a dash for a payment without a confirmation', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => exampleEmail('client-invoice')]));

        $adminId = $this->admin->getKey();

        // A payment recorded before the confirmation existed: stored as the system, with no confirmation row.
        app(PartnerContext::class)->runAsSystem(static function () use ($invoice, $adminId): void {
            $payment = new InvoicePayment;
            $payment->forceFill([
                'invoice_id' => $invoice->id,
                'currency' => $invoice->currency,
                'amount_minor' => 25000,
                'paid_on' => '2026-10-01',
                'method' => PaymentMethod::Transfer,
                'created_by' => $adminId,
            ])->save();
        });

        $tab = payAdminTab($invoice);

        expect($invoice->payments()->count())->toBe(1);

        $tab->assertSee('Potvrzení klientovi')
            ->assertDontSee('Odesláno')
            ->assertDontSee('Bez e-mailu')
            ->assertDontSee('Čeká na odeslání')
            ->assertDontSee('Neodesláno');
    });

    it('shows each payment with its own state', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => exampleEmail('client-invoice')]));

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '400.00', 'paid_on' => '2026-10-13']);
        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '100.00', 'paid_on' => '2026-10-14']);

        payAdminTab($invoice)->assertCountTableRecords(2)->assertSee('Odesláno');
    });

    it('shows the proforma payment confirmation too', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(['invoice_email' => exampleEmail('client-invoice')]), [], InvoiceKind::Proforma);

        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '400.00', 'paid_on' => '2026-10-14']);

        payAdminTab($proforma)->assertSee('Odesláno');
    });
});

describe('the Partner', function (): void {
    it('cannot reach the tab', function (): void {
        $client = InvoiceFixtures::client(['invoice_email' => exampleEmail('client-invoice')]);
        $invoice = InvoiceFixtures::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        payAdminTab($invoice)->assertForbidden();
    });
});
