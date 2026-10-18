<?php

declare(strict_types=1);

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CardPaymentResource;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\TodayPlan;
use App\Filament\Widgets\UnbilledPerClient;
use App\Filament\Widgets\UnpaidInvoices;
use App\Filament\Widgets\UnresolvedPayments;
use App\Filament\Widgets\UpcomingDeadlines;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentFixtures;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The dashboard card "Nevyřešené platby" (PA-08; UI-SPEC Surface J, U-9): an unresolved duplicate, unmatched payment or
 * refund appears on the Admin dashboard as soon as it exists, and nothing appears before. Every name and amount below
 * is invented; Stripe references come from the fixtures.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * The visible text of rendered markup: icons, comments and scripts removed, tags stripped, white space collapsed.
 */
function unresolvedCardText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

describe('the tracer', function (): void {
    it('stays absent with nothing unresolved and appears under the receivables card for a duplicate', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);

        expect(UnresolvedPayments::canView())->toBeFalse()
            ->and(unresolvedCardText((string) $this->get('/admin')->assertOk()->getContent()))->not->toContain('Nevyřešené platby');

        // A matched record is not waiting for anybody.
        CardPaymentFixtures::matched($invoice);

        expect(UnresolvedPayments::canView())->toBeFalse()
            ->and(unresolvedCardText((string) $this->get('/admin')->assertOk()->getContent()))->not->toContain('Nevyřešené platby');

        CardPaymentFixtures::duplicate($invoice);

        $page = unresolvedCardText((string) $this->get('/admin')->assertOk()->getContent());
        $html = Livewire::actingAs($this->admin)->test(UnresolvedPayments::class)->assertSuccessful()->html();
        $text = unresolvedCardText($html);

        expect(UnresolvedPayments::canView())->toBeTrue()
            ->and($text)->toContain('Nevyřešené platby')
            ->toContain('duplicitní 1')
            ->and($html)->toContain('fi-color-danger')
            ->toContain(e(CardPaymentResource::getUrl('index')))
            ->and($page)->toContain('Nevyřešené platby')
            ->toContain('Nezaplacené faktury')
            ->and(strpos($page, 'Nezaplacené faktury'))->toBeLessThan((int) strpos($page, 'Nevyřešené platby'));
    });

    it('is the third widget of the Admin dashboard, right after the receivables card, with its own card key', function (): void {
        expect((new Dashboard)->getWidgets())->toBe([HoursStats::class, TodayPlan::class, UnpaidInvoices::class, UnresolvedPayments::class, UnbilledPerClient::class, UpcomingDeadlines::class])
            ->and((new UnresolvedPayments)->getColumnSpan())->toBe('full')
            ->and(UnresolvedPayments::dashboardWidget())->toBe(DashboardWidget::UnresolvedPayments)
            ->and(DashboardWidget::UnresolvedPayments->value)->toBe('unresolved_payments');
    });
});
