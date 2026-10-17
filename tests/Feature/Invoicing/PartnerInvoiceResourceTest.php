<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Shared\Money\Money;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ListPartnerInvoices;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The Partner's read-only "Faktury" (IN-09, UI-SPEC Surface N, rulings R-26-2 and R-26-3): the issued documents of the
 * own client with their derived state, the detail with the frozen parties and lines, and the signed PDF link of plan
 * 10-38. Every name and amount is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Prague'));
});

/**
 * A payment of the document as the Admin; the clock must not stand before the payment date.
 */
function partnerInvoicePay(Invoice $invoice, string $amount, string $paidOn = '2026-10-01'): void
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    app(RecordPayment::class)->handle($admin, $invoice, ['amount' => $amount, 'paid_on' => $paidOn]);
}

describe('the tracer', function (): void {
    beforeEach(function (): void {
        $this->client = InvoiceFixtures::client();
        $this->invoice = InvoiceFixtures::issued($this->client, [InvoiceFixtures::item('Example work', '1', '10000.00')]);
        partnerInvoicePay($this->invoice, '4000.00');
        $this->partner = Canary::partnerFor($this->client->id);
    });

    it('lists the own partly paid invoice with its state and the amount still due', function (): void {
        $this->actingAs($this->partner);

        Livewire::test(ListPartnerInvoices::class)
            ->assertCanSeeTableRecords([$this->invoice])
            ->assertSee($this->invoice->number)
            ->assertSee('Částečně zaplaceno')
            ->assertSee(Money::ofMinor(1_000_000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(600_000, 'CZK')->format('cs'));
    });

    it('opens the detail with the sections of the document', function (): void {
        $this->actingAs($this->partner);

        $response = $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->invoice]))->assertOk();

        foreach (['Doklad', 'Dodavatel', 'Odběratel', 'Platební údaje', 'Položky', 'Souhrn', 'Example Supplier s.r.o.', 'Example work', 'Uhrazeno', 'Zbývá uhradit', 'Stáhnout PDF'] as $text) {
            $response->assertSee($text);
        }

        $response
            ->assertSee($this->invoice->number)
            ->assertSee(Money::ofMinor(400_000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(600_000, 'CZK')->format('cs'));
    });

    it('hands the Partner the signed PDF link of the document and the link returns the PDF', function (): void {
        $this->actingAs($this->partner);

        $component = Livewire::test(ViewPartnerInvoice::class, ['record' => $this->invoice->getKey()])
            ->callAction('downloadPdf');

        $target = $component->effects['redirect'] ?? null;

        expect($target)->toBeString();

        $path = parse_url(InvoicePdfUrl::download($this->invoice, $this->partner), PHP_URL_PATH);

        expect(parse_url($target, PHP_URL_PATH))->toBe($path);

        $this->get($target)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    });

    it('offers the PDF as a row action of the list as well', function (): void {
        $this->actingAs($this->partner);

        $component = Livewire::test(ListPartnerInvoices::class)
            ->callTableAction('downloadPdf', $this->invoice);

        expect($component->effects['redirect'] ?? null)->toBeString();
    });

    it('is absent from the global search and has no create, edit or delete page', function (): void {
        $this->actingAs($this->partner);

        expect(PartnerInvoiceResource::canGloballySearch())->toBeFalse()
            ->and(PartnerInvoiceResource::canCreate())->toBeFalse()
            ->and(array_keys(PartnerInvoiceResource::getPages()))->toBe(['index', 'view'])
            ->and(PartnerInvoiceResource::getRelations())->toBe([]);
    });

    it('keeps the navigation entry at sort 30 for the Partner', function (): void {
        $this->actingAs($this->partner);

        expect(PartnerInvoiceResource::canAccess())->toBeTrue()
            ->and(PartnerInvoiceResource::getNavigationSort())->toBe(30)
            ->and(PartnerInvoiceResource::getNavigationLabel())->toBe('Faktury');
    });
});
