<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Queries\PartnerPayButton;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * The "Zaplatit kartou" button of the Partner's invoice detail (PA-02; D-04, D-01, UI-SPEC Surfaces F and M, rulings
 * R11-15-1 and R11-15-2): the accent action in front of the PDF download, shown exactly while the document can be
 * paid by card. The gateway is the in-memory fake; every name and amount is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

    $this->client = CardPaymentSetup::client();
    $this->partner = Canary::partnerFor($this->client->id);
});

/**
 * The Partner's detail page of the document.
 */
function partnerPayPage(Invoice $invoice): Testable
{
    return Livewire::test(ViewPartnerInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The names of the header actions the page renders, in order.
 *
 * @return list<string>
 */
function partnerHeaderActions(Testable $page): array
{
    return collect($page->instance()->getCachedHeaderActions())
        ->filter(static fn ($action): bool => $action instanceof Action && $action->isVisible())
        ->map(static fn (Action $action): string => (string) $action->getName())
        ->values()
        ->all();
}

/**
 * A payment of the document as the Admin.
 */
function partnerPayRecord(Invoice $invoice, string $amount): void
{
    app(RecordPayment::class)->handle(Canary::admin(), $invoice, ['amount' => $amount, 'paid_on' => '2026-10-14']);
}

describe('the tracer', function (): void {
    it('gives the Partner the accent "Zaplatit kartou" in front of the PDF download, leading to the stable pay URL', function (): void {
        $invoice = CardPaymentSetup::issued($this->client);
        $url = PayUrl::forInvoice($invoice);

        $this->actingAs($this->partner);

        $page = partnerPayPage($invoice)
            ->assertActionVisible('payByCard')
            ->assertActionHasLabel('payByCard', 'Zaplatit kartou')
            ->assertActionHasColor('payByCard', 'primary')
            ->assertActionHasUrl('payByCard', $url)
            ->assertActionShouldOpenUrlInNewTab('payByCard')
            ->assertSee('Zaplatit kartou')
            ->assertSee('Otevře platební stránku v novém okně.')
            ->assertSee('Stáhnout PDF')
            ->assertDontSee('stripe.com');

        expect($url)->toBeString()
            ->and(partnerHeaderActions($page))->toBe(['payByCard', 'downloadPdf']);
    });

    it('offers the button on an open proforma as well', function (): void {
        $proforma = CardPaymentSetup::issued($this->client, InvoiceKind::Proforma);
        $url = app(PartnerContext::class)->runAsSystem(static fn (): ?string => PayUrl::forInvoice($proforma));

        $this->actingAs($this->partner);

        expect($url)->toBeString();

        partnerPayPage($proforma)->assertActionHasUrl('payByCard', $url);
    });

});

describe('when the header holds the PDF only', function (): void {
    it('drops the button when a partial payment deactivated the link, until the Admin creates a new one', function (): void {
        $invoice = CardPaymentSetup::issued($this->client);
        partnerPayRecord($invoice, '2500.00');

        $this->actingAs($this->partner);

        $page = partnerPayPage($invoice)->assertDontSee('Zaplatit kartou')->assertDontSee('Deaktivován');

        expect(partnerHeaderActions($page))->toBe(['downloadPdf']);
    });

    it('drops the button once the document is paid in full', function (): void {
        $invoice = CardPaymentSetup::issued($this->client);
        partnerPayRecord($invoice, '12500.00');

        $this->actingAs($this->partner);

        $page = partnerPayPage($invoice)->assertDontSee('Zaplatit kartou');

        expect(partnerHeaderActions($page))->toBe(['downloadPdf']);
    });

    it('drops the button once the document is cancelled by a storno', function (): void {
        $invoice = CardPaymentSetup::issued($this->client);
        app(IssueCreditNote::class)->storno(Canary::admin(), $invoice);

        $this->actingAs($this->partner);

        $page = partnerPayPage($invoice)->assertDontSee('Zaplatit kartou');

        expect(partnerHeaderActions($page))->toBe(['downloadPdf']);
    });

    it('shows no button while the link is still being prepared', function (): void {
        Queue::fake();
        $invoice = CardPaymentSetup::issued($this->client);

        $this->actingAs($this->partner);

        $page = partnerPayPage($invoice)->assertDontSee('Zaplatit kartou')->assertDontSee('připravuje');

        expect(partnerHeaderActions($page))->toBe(['downloadPdf']);
    });

    it('shows no button when the link could not be created, and no trace of the failure', function (): void {
        $this->stripe->failNextCreate('invalid_request');
        $invoice = CardPaymentSetup::issued($this->client);

        $this->actingAs($this->partner);

        $page = partnerPayPage($invoice)
            ->assertDontSee('Zaplatit kartou')
            ->assertDontSee('invalid_request')
            ->assertDontSee('selhalo');

        expect(partnerHeaderActions($page))->toBe(['downloadPdf']);
    });

    it('shows no button for a document whose card payment is switched off', function (): void {
        $client = InvoiceFixtures::client(['online_payment_enabled' => false]);
        $invoice = CardPaymentSetup::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        $page = partnerPayPage($invoice)->assertDontSee('Zaplatit kartou');

        expect(partnerHeaderActions($page))->toBe(['downloadPdf']);
    });
});

describe('the Partner-safe reader', function (): void {
    it('returns the stable URL for a payable document and nothing else', function (): void {
        $invoice = CardPaymentSetup::issued($this->client);

        $this->actingAs($this->partner);

        $record = Invoice::query()->whereKey($invoice->id)->firstOrFail();

        $expected = app(PartnerContext::class)->runAsSystem(static fn (): ?string => PayUrl::forInvoice($invoice));

        expect($expected)->toBeString()
            ->and(PartnerPayButton::urlFor($record))->toBe($expected);
    });

    it('returns null for a record the Partner scope does not load', function (): void {
        $other = CardPaymentSetup::issued(CardPaymentSetup::client());

        $this->actingAs($this->partner);

        // Loaded as the Admin sees it, then handed to the reader under the Partner session.
        $record = app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($other->id));

        expect(PartnerPayButton::urlFor($record))->toBeNull();
    });

    it('restores the Partner scope after the system read', function (): void {
        $invoice = CardPaymentSetup::issued($this->client);

        $this->actingAs($this->partner);

        $record = Invoice::query()->whereKey($invoice->id)->firstOrFail();
        PartnerPayButton::urlFor($record);

        expect(app(PartnerContext::class)->isSystem())->toBeFalse()
            ->and(PayUrl::forInvoice($record))->toBeNull();
    });
});
