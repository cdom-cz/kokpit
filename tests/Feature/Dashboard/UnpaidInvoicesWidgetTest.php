<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Shared\Money\Money;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\TodayPlan;
use App\Filament\Widgets\UnbilledPerClient;
use App\Filament\Widgets\UnpaidInvoices;
use App\Filament\Widgets\UnresolvedPayments;
use App\Filament\Widgets\UpcomingDeadlines;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The receivables card of the Admin dashboard (IN-05; D-18, D-20, ruling R-28-1, UI-SPEC Surface J): what is overdue,
 * what is still due and which paid advances wait for their invoice. Every name and amount below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Europe/Prague'));
});

/**
 * The visible text of rendered markup: icons, comments and scripts removed, tags stripped, white space (the no-break
 * spaces of the money format included) collapsed.
 */
function unpaidCardText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

/**
 * Money as the screens print it, with the white space collapsed the way {@see unpaidCardText()} collapses it.
 */
function unpaidCardMoney(string $major): string
{
    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', Money::fromMajor($major, 'CZK')->format('cs')));
}

/**
 * A client in CZK that pays within ten days.
 */
function unpaidCardClient(): Client
{
    return InvoiceFixtures::client(['payment_terms_days' => 10, 'hourly_rate' => Money::fromMajor('1000', 'CZK')]);
}

/**
 * An issued document of 10 000 on the given Prague day, which leaves the clock on the day after.
 */
function unpaidCardIssued(Client $client, string $day, InvoiceKind $kind = InvoiceKind::Invoice, string $amount = '10000.00'): Invoice
{
    test()->travelTo(CarbonImmutable::parse($day.' 10:00:00', 'Europe/Prague'));

    return InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', $amount)], $kind);
}

/**
 * The world of the tracer, read on 2026-10-20: one invoice due 2026-10-15 (five days ago) and one due 2026-10-30.
 */
function unpaidCardWorld(): Client
{
    $client = unpaidCardClient();

    unpaidCardIssued($client, '2026-10-05');
    unpaidCardIssued($client, '2026-10-20');

    return $client;
}

describe('the receivables card on the Admin dashboard', function (): void {
    it('renders nothing before the first issued invoice or proforma', function (): void {
        $text = unpaidCardText((string) $this->get('/admin')->assertOk()->getContent());

        expect(UnpaidInvoices::canView())->toBeFalse()
            ->and($text)->not->toContain('Nezaplacené faktury')
            ->not->toContain('Nic po splatnosti');

        // A draft is not an issued document.
        InvoiceFixtures::draft(unpaidCardClient(), [InvoiceFixtures::item('Example work', '1', '500.00')]);

        expect(UnpaidInvoices::canView())->toBeFalse();
    });

    it('shows the overdue count with its CZK sum in danger and the amount still due', function (): void {
        unpaidCardWorld();

        $html = Livewire::actingAs($this->admin)->test(UnpaidInvoices::class)->assertSuccessful()->html();
        $text = unpaidCardText($html);

        expect(UnpaidInvoices::canView())->toBeTrue()
            ->and($text)->toContain('Nezaplacené faktury')
            ->toContain('Po splatnosti')
            ->toContain('K úhradě')
            ->toContain('v Kč: '.unpaidCardMoney('10000.00'))
            ->not->toContain('Nic po splatnosti')
            ->not->toContain('Zálohy k vyfakturování')
            ->and($html)->toContain('fi-color-danger');
    });

    it('lists a paid proforma without its invoice as an advance waiting for the invoice', function (): void {
        $client = unpaidCardWorld();
        $proforma = unpaidCardIssued($client, '2026-10-20', InvoiceKind::Proforma, '4000.00');

        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '4000', 'paid_on' => '2026-10-20']);

        $text = unpaidCardText(Livewire::actingAs($this->admin)->test(UnpaidInvoices::class)->html());

        expect($text)->toContain('Zálohy k vyfakturování')
            ->toContain('Platba čeká na fakturu');
    });

    it('is the second card of the Admin dashboard, between the plan and the unbilled time', function (): void {
        unpaidCardWorld();

        $text = unpaidCardText((string) $this->get('/admin')->assertOk()->getContent());

        expect((new Dashboard)->getWidgets())->toBe([HoursStats::class, TodayPlan::class, UnpaidInvoices::class, UnresolvedPayments::class, UnbilledPerClient::class, UpcomingDeadlines::class])
            ->and((new UnpaidInvoices)->getColumnSpan())->toBe('full')
            ->and($text)->toContain('Nezaplacené faktury')
            ->toContain('Nevyfakturováno tento měsíc')
            ->and(strpos($text, 'Nezaplacené faktury'))->toBeLessThan((int) strpos($text, 'Nevyfakturováno tento měsíc'));
    });

    it('is refused to a Partner, also when mounted by force, and absent from the Partner dashboard', function (): void {
        $client = unpaidCardWorld();
        $partner = Canary::partnerFor($client->id);

        Livewire::actingAs($partner)->test(UnpaidInvoices::class)->assertForbidden();

        $text = unpaidCardText((string) $this->actingAs($partner)->get('/admin')->assertOk()->getContent());

        expect($text)->not->toContain('Nezaplacené faktury')
            ->not->toContain('Po splatnosti')
            ->not->toContain('K úhradě')
            ->not->toContain('Zálohy k vyfakturování')
            ->and(in_array(UnpaidInvoices::class, (new Dashboard)->getWidgets(), true))->toBeFalse();
    });
});

describe('the card as one of the collapsible dashboard cards', function (): void {
    it('shows success text at zero overdue and no advance row at zero', function (): void {
        $client = unpaidCardClient();
        unpaidCardIssued($client, '2026-10-05');
        $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00:00', 'Europe/Prague'));

        $html = Livewire::actingAs($this->admin)->test(UnpaidInvoices::class)->html();
        $text = unpaidCardText($html);

        expect($text)->toContain('Nic po splatnosti')
            ->not->toContain('Zálohy k vyfakturování')
            ->not->toContain('Platba čeká na fakturu')
            ->and($html)->not->toContain('fi-color-danger');
    });

    it('stores its own preference key and keeps the overdue count in the collapsed header', function (): void {
        unpaidCardWorld();

        Livewire::actingAs($this->admin)->test(UnpaidInvoices::class)
            ->call('storeCollapsedState', true)
            ->assertReturned(true);

        $rows = DB::table('dashboard_widget_preferences')->where('user_id', $this->admin->id)->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->widget_key)->toBe('unpaid_invoices')
            ->and($rows[0]->widget_key)->toBe(DashboardWidget::UnpaidInvoices->value);

        $fresh = Livewire::actingAs($this->admin)->test(UnpaidInvoices::class);

        expect($fresh->get('collapsed'))->toBeTrue()
            ->and($fresh->html())->toContain('aria-expanded="false"')
            ->and(unpaidCardText($fresh->html()))->toContain('Po splatnosti: 1');
    });

    it('does not offer the card to a Partner as a state to store', function (): void {
        expect(DashboardWidget::UnpaidInvoices->isPartnerWidget())->toBeFalse();
    });

    it('links each stat to its list tab', function (): void {
        $client = unpaidCardWorld();
        $proforma = unpaidCardIssued($client, '2026-10-20', InvoiceKind::Proforma, '4000.00');
        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '4000', 'paid_on' => '2026-10-20']);

        $html = Livewire::actingAs($this->admin)->test(UnpaidInvoices::class)->html();

        foreach (['overdue', 'unpaid', 'awaiting_invoice'] as $tab) {
            expect($html)->toContain(e(InvoiceResource::getUrl('index', ['tab' => $tab])));
        }
    });

    it('agrees with the counts of the list tabs', function (): void {
        // Issue dates only move forward within a series: two overdue invoices first, then one that is still due.
        $client = unpaidCardClient();
        unpaidCardIssued($client, '2026-10-05');
        unpaidCardIssued($client, '2026-10-06');
        unpaidCardIssued($client, '2026-10-20');
        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'Europe/Prague'));

        $counts = app(ReceivablesReader::class)->tabCounts(ReceivablesReader::today());
        $text = unpaidCardText(Livewire::actingAs($this->admin)->test(UnpaidInvoices::class)->html());

        expect($counts['overdue'])->toBe(2)
            ->and($counts['unpaid'])->toBe(1);

        preg_match('/Po splatnosti (\d+)/', $text, $overdue);
        preg_match('/K úhradě (\d+)/', $text, $unpaid);

        expect((int) ($overdue[1] ?? -1))->toBe($counts['overdue'])
            ->and((int) ($unpaid[1] ?? -1))->toBe($counts['unpaid']);
    });

    it('reads the figures again when a payment is saved elsewhere', function (): void {
        $client = unpaidCardClient();
        $overdue = unpaidCardIssued($client, '2026-10-05');
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Prague'));

        $component = Livewire::actingAs($this->admin)->test(UnpaidInvoices::class);

        expect(unpaidCardText($component->html()))->not->toContain('Nic po splatnosti');

        app(RecordPayment::class)->handle($this->admin, $overdue, ['amount' => '10000', 'paid_on' => '2026-10-20']);

        $component->dispatch('invoice-payment-saved');

        expect(unpaidCardText($component->html()))->toContain('Nic po splatnosti');
    });
});
