<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Enums\CashReceiptLockState;
use App\Domain\Invoicing\Enums\PaymentConfirmationState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Jobs\SendPaymentConfirmation;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Indicators\UnsentInvoiceEmailsIndicator;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\MailFixtures;

/*
 * The whole rule of Phase 16 in one readable test (P16-01, P16-03, P16-04, P16-07, P16-08, P16-09; D-02, D-04, D-05, D-06,
 * D-07, D-08): a client who is served by hand. The Admin switches the document e-mails off on the client edit page,
 * issues an invoice through the real pipeline and meets no send dialog and no send action, only a gray note; a crafted
 * queue call is refused; the invoice is locked by hand from the open callout; a cash payment is recorded from the Platby
 * tab with its confirmation written as skipped and no job queued; its receipt is open and is locked by hand; the System
 * page stays Ok; switching the e-mails on again revives nothing and offers the send again. The queue is faked, so a job
 * that should not exist cannot hide; every name, address and amount is fictional.
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
 * Sets the switch of the client through the edit page, as the Admin does.
 */
function handServedJourneySwitch(Client $client, bool $enabled): void
{
    test()->actingAs(test()->admin);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['document_emails_enabled' => $enabled])
        ->call('save')
        ->assertHasNoFormErrors();
}

/**
 * The detail of the document, opened plainly or the way the issue opens it (`send=1`).
 */
function handServedJourneyPage(Invoice $invoice, bool $send = false): Testable
{
    return ($send ? Livewire::withQueryParams(['send' => 1]) : Livewire::withQueryParams([]))
        ->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The Platby tab of the document.
 */
function handServedJourneyTab(Invoice $invoice): Testable
{
    return Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class]);
}

/**
 * The rows of one audit event of a subject, oldest first.
 *
 * @return list<Activity>
 */
function handServedJourneyActivity(string $type, string $id, string $event): array
{
    return Activity::query()
        ->where('subject_type', $type)
        ->where('subject_id', $id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * The confirmation row of a payment, read as the system.
 */
function handServedJourneyConfirmation(InvoicePayment $payment): PaymentConfirmation
{
    return app(PartnerContext::class)->runAsSystem(static fn (): PaymentConfirmation => PaymentConfirmation::query()->where('invoice_payment_id', $payment->id)->sole());
}

/**
 * The receipt of a payment, read fresh.
 */
function handServedJourneyReceipt(InvoicePayment $payment): CashReceipt
{
    return CashReceipt::query()->where('invoice_payment_id', $payment->id)->sole();
}

/**
 * The invoice row, read fresh.
 */
function handServedJourneyInvoice(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

it('serves a client by hand from the switch to both locks, and switching on again revives nothing', function (): void {
    $address = exampleEmail('client-invoice');
    $client = InvoiceFixtures::client(['invoice_email' => $address]);

    // --- 1. The Admin switches the document e-mails off on the client edit page; one history row names the change ------
    handServedJourneySwitch($client, false);

    expect($client->refresh()->document_emails_enabled)->toBeFalse();

    // --- 2. An invoice is issued through the real pipeline; the detail opened after the issue has no dialog and no send ---
    $invoice = InvoiceFixtures::issued($client);
    $this->actingAs($this->admin);

    handServedJourneyPage($invoice, send: true)
        ->assertDontSeeHtml('wire:init="openSendDialog"')
        ->call('openSendDialog')
        ->assertActionNotMounted('sendEmail')
        ->assertActionHidden('sendEmail')
        ->assertSee(__('invoices.detail.banners.email_disabled'));

    // --- 3. A crafted queue call is refused with no row and no job --------------------------------------------------------
    $refused = [];

    try {
        app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, [
            'recipient' => $address,
            'subject' => 'Example subject',
            'body' => 'Example body',
            'attach_work_report' => false,
        ]);
    } catch (ValidationException $exception) {
        $refused = $exception->errors();
    }

    expect($refused)->toBe(['invoice' => [__('invoices.errors.email_client_disabled')]])
        ->and(InvoiceEmail::query()->where('invoice_id', $invoice->id)->count())->toBe(0);

    Queue::assertNotPushed(SendInvoiceEmail::class);

    // --- 4. The Admin locks the open invoice by hand from the open callout; one row names him, no e-mail row exists -------
    expect(handServedJourneyInvoice($invoice)->isLocked())->toBeFalse();

    handServedJourneyPage($invoice)
        ->assertActionVisible(TestAction::make('lock')->schemaComponent('open_for_edits', schema: 'infolist'))
        ->callAction(TestAction::make('lock')->schemaComponent('open_for_edits', schema: 'infolist'))
        ->assertHasNoActionErrors()
        ->assertActionVisible(TestAction::make('unlock')->schemaComponent('locked', schema: 'infolist'));

    $locked = handServedJourneyActivity('invoice', $invoice->id, 'invoice_locked_manually');

    expect(handServedJourneyInvoice($invoice)->isLocked())->toBeTrue()
        ->and($locked)->toHaveCount(1)
        ->and($locked[0]->causer_id)->toBe($this->admin->id)
        ->and(handServedJourneyActivity('invoice', $invoice->id, 'invoice_locked'))->toBe([])
        ->and(InvoiceEmail::query()->where('invoice_id', $invoice->id)->count())->toBe(0);

    // --- 5. A cash payment is recorded from the Platby tab; the dialog says that no confirmation will be sent ------------
    handServedJourneyTab($invoice)
        ->mountAction(TestAction::make('recordPayment')->table())
        ->assertMountedActionModalSee(__('invoices.payments.record.confirmation_hint_disabled'))
        ->assertMountedActionModalDontSee('Klientovi odejde na')
        ->fillForm(['amount' => '400.00', 'method' => 'cash'], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(__('invoices.payments.record.toast_invoice'));

    $payment = $invoice->payments()->sole();
    $confirmation = handServedJourneyConfirmation($payment);

    expect($payment->amount_minor)->toBe(40000)
        ->and($confirmation->state)->toBe(PaymentConfirmationState::Skipped)
        ->and($confirmation->recipient)->toBe($address)
        ->and($confirmation->sent_at)->toBeNull()
        ->and($confirmation->failure_code)->toBeNull()
        ->and(MailFixtures::sent())->toBe([]);

    Queue::assertNotPushed(SendPaymentConfirmation::class);

    // --- 6. The receipt is open, not sent; the Admin locks it by hand from the Platby tab; one row names him ---------------
    $receipt = handServedJourneyReceipt($payment);

    expect($receipt->lock_state)->toBe(CashReceiptLockState::Open)
        ->and($receipt->isCancelled())->toBeFalse();

    handServedJourneyTab($invoice)
        ->assertSee($receipt->number)
        ->assertSee('Otevřený')
        ->assertTableActionVisible('lockCashReceipt', $payment)
        ->callAction(TestAction::make('lockCashReceipt')->table($payment))
        ->assertHasNoActionErrors()
        ->assertTableActionHidden('lockCashReceipt', $payment)
        ->assertTableActionVisible('unlockCashReceipt', $payment);

    $receiptLocks = handServedJourneyActivity('cash_receipt', $receipt->id, 'cash_receipt_locked_manually');

    expect(handServedJourneyReceipt($payment)->lock_state)->toBe(CashReceiptLockState::Locked)
        ->and($receiptLocks)->toHaveCount(1)
        ->and($receiptLocks[0]->causer_id)->toBe($this->admin->id)
        ->and(handServedJourneyActivity('cash_receipt', $receipt->id, 'cash_receipt_locked'))->toBe([]);

    // --- 7. The System page stays quiet: a skipped confirmation and a hand-served client are no unsent e-mail ---------------
    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    // --- 8. Switching on again revives nothing and offers the send again; the client history holds both changes ------------
    handServedJourneySwitch($client, true);
    $this->actingAs($this->admin);

    expect(handServedJourneyConfirmation($payment)->state)->toBe(PaymentConfirmationState::Skipped)
        ->and(handServedJourneyConfirmation($payment)->sent_at)->toBeNull()
        ->and(InvoiceEmail::query()->where('invoice_id', $invoice->id)->count())->toBe(0)
        ->and(MailFixtures::sent())->toBe([])
        ->and((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    Queue::assertNotPushed(SendInvoiceEmail::class);
    Queue::assertNotPushed(SendPaymentConfirmation::class);

    handServedJourneyPage($invoice)
        ->assertActionVisible('sendEmail')
        ->assertDontSee(__('invoices.detail.banners.email_disabled'));

    $changes = array_values(array_filter(
        handServedJourneyActivity('client', $client->id, 'updated'),
        static fn (Activity $row): bool => array_key_exists('document_emails_enabled', $row->attribute_changes?->get('attributes') ?? []),
    ));

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->attribute_changes?->get('attributes'))->toBe(['document_emails_enabled' => false])
        ->and($changes[0]->attribute_changes?->get('old'))->toBe(['document_emails_enabled' => true])
        ->and($changes[1]->attribute_changes?->get('attributes'))->toBe(['document_emails_enabled' => true])
        ->and($changes[1]->attribute_changes?->get('old'))->toBe(['document_emails_enabled' => false])
        ->and($changes[0]->causer_id)->toBe($this->admin->id)
        ->and($changes[1]->causer_id)->toBe($this->admin->id);
});
