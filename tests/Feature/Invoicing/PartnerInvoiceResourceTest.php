<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ListPartnerInvoices;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use App\Filament\Partner\Resources\PartnerInvoiceResource\RelationManagers\PartnerCashReceiptsRelationManager;
use App\Filament\Support\InvoiceColumns;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;
use Tests\Support\InvoiceScenario;

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

/**
 * An issued, still open document switched to online payment the way the database allows it (Phase 14, plan 14-02): the
 * method is online, the bank snapshot is gone, the card switch is on and a first pay token is set.
 */
function partnerInvoiceOnline(Invoice $issued): Invoice
{
    $token = PayToken::generate();

    app(PartnerContext::class)->runAsSystem(static function () use ($issued, $token): void {
        DB::update("update invoices set payment_method = 'online', bank_snapshot = null where id = ?", [$issued->id]);
        DB::update('update invoice_admin set card_payment_enabled = true, pay_token = ? where invoice_id = ?', [$token, $issued->id]);
    });

    return Invoice::query()->whereKey($issued->id)->firstOrFail();
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

    it('is absent from the global search and has no create, edit or delete page', function (): void {
        $this->actingAs($this->partner);

        expect(PartnerInvoiceResource::canGloballySearch())->toBeFalse()
            ->and(PartnerInvoiceResource::canCreate())->toBeFalse()
            ->and(array_keys(PartnerInvoiceResource::getPages()))->toBe(['index', 'view'])
            ->and(PartnerInvoiceResource::getRelations())->toBe([PartnerCashReceiptsRelationManager::class]);
    });
});

describe('every document state', function (): void {
    beforeEach(function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));

        $this->clientA = InvoiceFixtures::client();
        $this->clientB = InvoiceFixtures::client();
        $scenario = InvoiceScenario::forClients($this->clientA->id, $this->clientB->id);
        $this->a = $scenario['a'];
        $this->b = $scenario['b'];
        $this->partner = Canary::partnerFor($this->clientA->id);
    });

    it('lists exactly the issued documents of the own client, in 25 per page', function (): void {
        $this->actingAs($this->partner);

        $own = [$this->a['invoice'], $this->a['unpaid'], $this->a['proforma'], $this->a['cancelled'], $this->a['credit_note'], $this->a['eur']];
        $foreign = [$this->a['draft'], $this->b['draft'], $this->b['invoice'], $this->b['unpaid'], $this->b['proforma'], $this->b['cancelled'], $this->b['credit_note'], $this->b['eur']];

        $component = Livewire::test(ListPartnerInvoices::class)
            ->assertCountTableRecords(6)
            ->assertCanSeeTableRecords($own)
            ->assertCanNotSeeTableRecords($foreign);

        $table = $component->instance()->getTable();

        expect($table->getDefaultPaginationPageOption())->toBe(25)
            ->and(array_keys($table->getColumns()))->toBe(InvoiceColumns::PARTNER_COLUMN_NAMES)
            ->and(array_keys($table->getFilters()))->toBe(InvoiceColumns::PARTNER_FILTER_NAMES)
            ->and(array_filter($table->getColumns(), static fn ($column): bool => $column->isSearchable()))->toBe([])
            ->and($table->getBulkActions())->toBe([]);
    });

    it('reads each state as the client does and shows "—" where nothing is owed', function (): void {
        $this->actingAs($this->partner);

        Livewire::test(ListPartnerInvoices::class)
            ->assertTableColumnFormattedStateSet('status', 'Částečně zaplaceno', $this->a['invoice'])
            ->assertTableColumnFormattedStateSet('status', 'Čeká na úhradu', $this->a['unpaid'])
            ->assertTableColumnFormattedStateSet('status', 'Zaplaceno', $this->a['proforma'])
            ->assertTableColumnFormattedStateSet('status', 'Stornováno', $this->a['cancelled'])
            ->assertTableColumnFormattedStateSet('status', 'Dobropis', $this->a['credit_note'])
            ->assertTableColumnFormattedStateSet('status', 'Čeká na úhradu', $this->a['eur'])
            ->assertTableColumnFormattedStateSet('remaining', Money::ofMinor(400_000, 'CZK')->format('cs'), $this->a['unpaid'])
            ->assertTableColumnFormattedStateSet('remaining', Money::ofMinor(10_000, 'EUR')->format('cs'), $this->a['eur'])
            ->assertTableColumnFormattedStateSet('remaining', '—', $this->a['proforma'])
            ->assertTableColumnFormattedStateSet('remaining', '—', $this->a['cancelled'])
            ->assertTableColumnFormattedStateSet('remaining', '—', $this->a['credit_note'])
            ->assertTableColumnFormattedStateSet('total', Money::ofMinor(10_000, 'EUR')->format('cs'), $this->a['eur'])
            ->assertTableColumnFormattedStateSet('kind', 'Zálohová faktura', $this->a['proforma'])
            ->assertTableColumnFormattedStateSet('kind', 'Dobropis', $this->a['credit_note'])
            ->assertTableColumnHasDescription('status', null, $this->a['unpaid']);
    });

    it('marks an overdue document with the danger date and the days it is late', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:30:00', 'Europe/Prague'));
        $this->actingAs($this->partner);

        Livewire::test(ListPartnerInvoices::class)
            ->assertTableColumnFormattedStateSet('status', 'Po splatnosti', $this->a['unpaid'])
            ->assertTableColumnFormattedStateSet('status', 'Po splatnosti', $this->a['invoice'])
            ->assertTableColumnFormattedStateSet('status', 'Zaplaceno', $this->a['proforma'])
            ->assertTableColumnHasDescription('due_date', 'Po splatnosti o 8 dní', $this->a['unpaid'])
            ->assertTableColumnHasDescription('status', 'Částečně zaplaceno', $this->a['invoice'])
            ->assertTableColumnHasDescription('due_date', null, $this->a['proforma'])
            ->assertTableColumnHasDescription('due_date', null, $this->a['cancelled']);
    });

    it('filters by kind and by state with the very rules of the states', function (): void {
        $this->actingAs($this->partner);

        Livewire::test(ListPartnerInvoices::class)
            ->filterTable('kind', 'credit_note')
            ->assertCanSeeTableRecords([$this->a['credit_note']])
            ->assertCanNotSeeTableRecords([$this->a['invoice'], $this->a['unpaid'], $this->a['proforma']])
            ->removeTableFilter('kind')
            ->filterTable('status', 'unpaid')
            ->assertCanSeeTableRecords([$this->a['unpaid'], $this->a['eur']])
            ->assertCanNotSeeTableRecords([$this->a['invoice'], $this->a['proforma'], $this->a['cancelled'], $this->a['credit_note']])
            ->filterTable('status', 'partially_paid')
            ->assertCanSeeTableRecords([$this->a['invoice']])
            ->assertCanNotSeeTableRecords([$this->a['unpaid'], $this->a['eur'], $this->a['proforma']])
            ->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$this->a['proforma']])
            ->assertCanNotSeeTableRecords([$this->a['unpaid'], $this->a['invoice'], $this->a['cancelled'], $this->a['credit_note']])
            ->filterTable('status', 'cancelled')
            ->assertCanSeeTableRecords([$this->a['cancelled']])
            ->assertCanNotSeeTableRecords([$this->a['credit_note'], $this->a['unpaid']])
            ->filterTable('status', 'credit_note')
            ->assertCanSeeTableRecords([$this->a['credit_note']])
            ->assertCanNotSeeTableRecords([$this->a['cancelled'], $this->a['unpaid']]);

        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:30:00', 'Europe/Prague'));

        Livewire::test(ListPartnerInvoices::class)
            ->filterTable('status', 'overdue')
            ->assertCanSeeTableRecords([$this->a['unpaid'], $this->a['invoice'], $this->a['eur']])
            ->assertCanNotSeeTableRecords([$this->a['proforma'], $this->a['cancelled'], $this->a['credit_note']]);
    });

    it('names the credit note on a cancelled invoice and the invoice on a converted proforma', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);
        $proforma = InvoiceFixtures::issued($this->clientA, [InvoiceFixtures::item('Converted advance', '1', '1500.00')], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle($admin, $proforma, ['amount' => '1500.00', 'paid_on' => '2026-10-14']);
        $converted = app(ConvertProforma::class)->handle($admin, $proforma);

        $this->actingAs($this->partner);

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->a['cancelled']]))
            ->assertOk()
            ->assertSee('Stornováno')
            ->assertSee('Související doklady')
            ->assertSee($this->a['credit_note']->number);

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $proforma]))
            ->assertOk()
            ->assertSee('Související doklady')
            ->assertSee($converted->number);

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->a['unpaid']]))
            ->assertOk()
            ->assertDontSee('Související doklady');
    });

    it('shows the overdue callout on an overdue document only, and no payment block on a credit note', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:30:00', 'Europe/Prague'));
        $this->actingAs($this->partner);

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->a['unpaid']]))
            ->assertOk()
            ->assertSee('Faktura je po splatnosti o 8 dní. Zbývá uhradit')
            ->assertSee(Money::ofMinor(400_000, 'CZK')->format('cs'));

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->a['proforma']]))
            ->assertOk()
            ->assertDontSee('Faktura je po splatnosti');

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->a['credit_note']]))
            ->assertOk()
            ->assertSee('Dobropis')
            ->assertDontSee('Platební údaje')
            ->assertDontSee('Zbývá uhradit');
    });

    it('answers 404 to the detail of a draft, of another client\'s document and of an unknown or malformed id', function (): void {
        $this->actingAs($this->partner);

        $ids = [
            'own draft' => $this->a['draft']->id,
            'foreign invoice' => $this->b['invoice']->id,
            'foreign draft' => $this->b['draft']->id,
            'unknown uuid' => (string) Str::uuid(),
            'malformed id' => 'not-an-id',
        ];

        foreach ($ids as $name => $id) {
            $response = $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $id]));

            expect($response->getStatusCode())->toBe(404, $name);

            foreach ([$this->b['canaries']['invoice_note'], $this->b['invoice']->number, $this->a['draft']->id] as $trace) {
                expect(str_contains((string) $response->getContent(), $trace))->toBeFalse($name);
            }
        }
    });

    it('refuses the Admin the Partner resource and keeps one Faktury entry in the navigation', function (): void {
        $this->actingAs(Canary::admin());

        $this->get('/admin/my-invoices')->assertForbidden();
        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->a['unpaid']]))->assertForbidden();

        $labels = collect(Filament::getNavigation())
            ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getLabel())->all())
            ->filter(static fn (string $label): bool => $label === 'Faktury')
            ->all();

        expect($labels)->toHaveCount(1);
    });

    it('refuses a Partner every Admin invoice URL', function (): void {
        $this->actingAs($this->partner);

        $this->get('/admin/invoices')->assertForbidden();
        $this->get('/admin/invoices/'.$this->a['unpaid']->id)->assertForbidden();
        $this->get('/admin/invoices/'.$this->a['draft']->id.'/edit')->assertForbidden();

        $labels = collect(Filament::getNavigation())
            ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getLabel())->all())
            ->filter(static fn (string $label): bool => $label === 'Faktury')
            ->all();

        expect($labels)->toHaveCount(1);
    });
});

describe('the payment method (Phase 14)', function (): void {
    beforeEach(function (): void {
        $this->client = InvoiceFixtures::client();
        $this->partner = Canary::partnerFor($this->client->id);
    });

    it('shows the method, the account lines and the variable symbol of a transfer invoice', function (): void {
        $invoice = InvoiceFixtures::issued($this->client, [InvoiceFixtures::item('Example work', '1', '10000.00')]);
        $this->actingAs($this->partner);

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $invoice]))
            ->assertOk()
            ->assertSee('Způsob úhrady')
            ->assertSee('Bankovní převod')
            ->assertDontSee('Online platba kartou')
            ->assertSee('Bankovní účet')
            ->assertSee(trim(chunk_split(FictionalIban::czech('12'), 4, ' ')))
            ->assertSee('Variabilní symbol')
            ->assertSee((string) $invoice->variable_symbol);
    });

    it('shows an online invoice with its method and amount and without the account or the variable symbol', function (): void {
        $issued = InvoiceFixtures::issued($this->client, [InvoiceFixtures::item('Example work', '1', '10000.00')]);
        $online = partnerInvoiceOnline($issued);
        $this->actingAs($this->partner);

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $online]))
            ->assertOk()
            ->assertSee('Způsob úhrady')
            ->assertSee('Online platba kartou')
            ->assertDontSee('Bankovní převod')
            ->assertSee(Money::ofMinor(1_000_000, 'CZK')->format('cs'))
            ->assertSee('Částka k úhradě')
            ->assertDontSee(FictionalIban::czech('12'))
            ->assertDontSee(trim(chunk_split(FictionalIban::czech('12'), 4, ' ')))
            ->assertDontSee('Bankovní účet')
            ->assertDontSee('Variabilní symbol');
    });

    it('shows a cash invoice with its method and amount and without the account or either variable symbol (Phase 15, D-02)', function (): void {
        $issued = InvoiceFixtures::issued($this->client, [InvoiceFixtures::item('Example work', '1', '10000.00')]);

        app(PartnerContext::class)->runAsSystem(static function () use ($issued): void {
            DB::update("update invoices set payment_method = 'cash', bank_snapshot = null where id = ?", [$issued->id]);
            DB::update('update invoice_admin set card_payment_enabled = false, pay_token = null where invoice_id = ?', [$issued->id]);
        });

        $cash = Invoice::query()->whereKey($issued->id)->firstOrFail();
        $this->actingAs($this->partner);

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $cash]))
            ->assertOk()
            ->assertSee('Způsob úhrady')
            ->assertSee('Hotově')
            ->assertDontSee('Bankovní převod')
            ->assertSee(Money::ofMinor(1_000_000, 'CZK')->format('cs'))
            ->assertSee('Částka k úhradě')
            ->assertDontSee(FictionalIban::czech('12'))
            ->assertDontSee(trim(chunk_split(FictionalIban::czech('12'), 4, ' ')))
            ->assertDontSee('Bankovní účet')
            ->assertDontSee('Variabilní symbol');
    });
});
