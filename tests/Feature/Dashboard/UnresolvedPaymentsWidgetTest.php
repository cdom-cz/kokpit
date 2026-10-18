<?php

declare(strict_types=1);

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CardPaymentResource;
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

describe('the Czech plural forms', function (): void {
    it('prints each non-zero part in its plural form, in the order duplicate, unmatched, refund', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        CardPaymentFixtures::duplicate($invoice);

        foreach (range(1, 3) as $ignored) {
            CardPaymentFixtures::unmatched($invoice);
        }

        foreach (range(1, 5) as $ignored) {
            CardPaymentFixtures::refund($invoice);
        }

        $text = unresolvedCardText(Livewire::actingAs($this->admin)->test(UnresolvedPayments::class)->html());

        expect($text)->toContain('Nevyřešené platby 9')
            ->toContain('duplicitní 1, nespárované 3, vrácení 5');
    });

    it('omits a part at zero and uses the genitive from five', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());

        foreach (range(1, 5) as $ignored) {
            CardPaymentFixtures::unmatched($invoice);
        }

        foreach (range(1, 6) as $ignored) {
            CardPaymentFixtures::duplicate($invoice);
        }

        $text = unresolvedCardText(Livewire::actingAs($this->admin)->test(UnresolvedPayments::class)->html());

        expect($text)->toContain('duplicitních 6, nespárovaných 5')
            ->not->toContain('vrácení')
            ->not->toContain('nespárovaná 1');
    });

    it('uses the singular of the unmatched payment for one', function (): void {
        CardPaymentFixtures::unmatched();

        $text = unresolvedCardText(Livewire::actingAs($this->admin)->test(UnresolvedPayments::class)->html());

        expect($text)->toContain('nespárovaná 1')
            ->not->toContain('duplicitní')
            ->not->toContain('vrácení');
    });

    it('does not count a resolved record', function (): void {
        CardPaymentFixtures::resolved();
        CardPaymentFixtures::refund();

        $text = unresolvedCardText(Livewire::actingAs($this->admin)->test(UnresolvedPayments::class)->html());

        expect($text)->toContain('vrácení 1')
            ->not->toContain('nespárovan');
    });
});

describe('the card as one of the collapsible dashboard cards', function (): void {
    it('stores its own preference key and keeps the count in the collapsed header', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        CardPaymentFixtures::duplicate($invoice);
        CardPaymentFixtures::unmatched($invoice);

        Livewire::actingAs($this->admin)->test(UnresolvedPayments::class)
            ->call('storeCollapsedState', true)
            ->assertReturned(true);

        $rows = DB::table('dashboard_widget_preferences')->where('user_id', $this->admin->id)->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->widget_key)->toBe('unresolved_payments')
            ->and($rows[0]->widget_key)->toBe(DashboardWidget::UnresolvedPayments->value);

        $fresh = Livewire::actingAs($this->admin)->test(UnresolvedPayments::class);
        $html = $fresh->html();

        expect($fresh->get('collapsed'))->toBeTrue()
            ->and($html)->toContain('aria-expanded="false"')
            ->and(unresolvedCardText($html))->toContain('Nevyřešené platby: 2');
    });

    it('does not offer the card to a Partner as a state to store', function (): void {
        expect(DashboardWidget::UnresolvedPayments->isPartnerWidget())->toBeFalse();
    });

    it('is removed on the next render when the last unresolved record is resolved', function (): void {
        $record = CardPaymentFixtures::unmatched();

        $component = Livewire::actingAs($this->admin)->test(UnresolvedPayments::class);

        expect(unresolvedCardText($component->html()))->toContain('Nevyřešené platby');

        app(PartnerContext::class)->runAsSystem(static function () use ($record): void {
            $record->forceFill([
                'resolution' => CardPaymentResolution::Noted,
                'resolved_at' => CarbonImmutable::now(),
                'resolved_by' => Canary::admin()->getKey(),
                'resolution_note' => 'Returned in the Stripe dashboard (fictional).',
            ])->save();
        });

        $component->dispatch('card-payment-resolved')->assertSuccessful();

        expect(unresolvedCardText($component->html()))->not->toContain('Nevyřešené platby')
            ->and(UnresolvedPayments::canView())->toBeFalse()
            ->and(unresolvedCardText((string) $this->get('/admin')->assertOk()->getContent()))->not->toContain('Nevyřešené platby');
    });
});

describe('the card and a Partner', function (): void {
    it('is refused when mounted by force and absent from the Partner dashboard, with unresolved records of the own client', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);
        CardPaymentFixtures::duplicate($invoice);
        CardPaymentFixtures::unmatched($invoice);
        $partner = Canary::partnerFor($client->id);

        Livewire::actingAs($partner)->test(UnresolvedPayments::class)->assertForbidden();

        $text = unresolvedCardText((string) $this->actingAs($partner)->get('/admin')->assertOk()->getContent());

        expect(UnresolvedPayments::canView())->toBeFalse()
            ->and($text)->not->toContain('Nevyřešené platby')
            ->not->toContain('duplicitní')
            ->not->toContain('nespárovan')
            ->and(in_array(UnresolvedPayments::class, (new Dashboard)->getWidgets(), true))->toBeFalse();
    });
});
