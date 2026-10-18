<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\DeactivatePaymentLink;
use App\Domain\Payments\Queries\PaymentLinkStatus;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ClientInvoicesRelationManager;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FakeStripeGateway;
use Tests\Support\InvoiceFixtures;

/*
 * The card link in the Admin invoice list (PA-01, PA-04; UI-SPEC "Invoice list"): the filter "Platební odkaz" and the
 * toggleable icon column "Platba kartou", hidden by default. One issued document per state; every name and amount is
 * fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    $this->stripe = FakeStripeGateway::install();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * One issued document per card-link state, of one client unless another is given.
 *
 * @return array{active: Invoice, pending: Invoice, failed: Invoice, deactivated: Invoice, off: Invoice, draft: Invoice, credit: Invoice}
 */
function linkPortfolio(?Client $client = null): array
{
    $client ??= CardPaymentSetup::client();

    test()->stripe->failNextCreate('invalid_request');
    $failed = CardPaymentSetup::issued($client);
    $active = CardPaymentSetup::issued($client);
    $deactivated = CardPaymentSetup::issued($client);
    app(DeactivatePaymentLink::class)->handle(Canary::admin(), $deactivated);

    // The client's flag is the default of the draft switch, so a document issued while it is off has the card switch off.
    $client->forceFill(['online_payment_enabled' => false])->save();
    $off = CardPaymentSetup::issued($client);
    $client->forceFill(['online_payment_enabled' => true])->save();

    $credit = InvoiceFixtures::stornoed(CardPaymentSetup::issued($client));
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '1000.00')]);

    Queue::fake();
    $pending = CardPaymentSetup::issued($client);

    return ['active' => $active, 'pending' => $pending, 'failed' => $failed, 'deactivated' => $deactivated, 'off' => $off, 'draft' => $draft, 'credit' => $credit];
}

/**
 * The page of the filter and of the column with the whole table switched on.
 *
 * @param  list<Invoice>  $see
 * @param  list<Invoice>  $notSee
 */
function linkFilterShows(Testable $table, string $value, array $see, array $notSee): void
{
    $table->filterTable('payment_link', $value)
        ->assertCanSeeTableRecords($see)
        ->assertCanNotSeeTableRecords($notSee);
}

describe('the filter', function (): void {
    it('lists exactly the documents in each state and shows its indicator chip', function (): void {
        $portfolio = linkPortfolio();
        $states = ['active', 'pending', 'failed', 'deactivated', 'off'];
        $labels = ['active' => 'Aktivní', 'pending' => 'Připravuje se', 'failed' => 'Selhalo', 'deactivated' => 'Deaktivovaný', 'off' => 'Vypnuto'];

        foreach ($states as $state) {
            $others = array_values(array_map(static fn (string $name): Invoice => $portfolio[$name], array_diff(array_keys($portfolio), [$state])));
            $table = Livewire::test(ListInvoices::class);

            linkFilterShows($table, $state, [$portfolio[$state]], $others);

            $indicators = array_map(static fn ($indicator): string => (string) $indicator->getLabel(), $table->instance()->getTable()->getFilterIndicators());

            expect($indicators)->toContain('Platební odkaz: '.$labels[$state]);
        }
    });

    it('shows every document again when cleared', function (): void {
        $portfolio = linkPortfolio();

        Livewire::test(ListInvoices::class)
            ->filterTable('payment_link', 'failed')
            ->removeTableFilter('payment_link')
            ->assertCanSeeTableRecords(array_values($portfolio));
    });

    it('works on the invoice tab of a client', function (): void {
        $client = CardPaymentSetup::client();
        $portfolio = linkPortfolio($client);

        $table = Livewire::test(ClientInvoicesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);

        linkFilterShows($table, 'failed', [$portfolio['failed']], [$portfolio['active'], $portfolio['off'], $portfolio['deactivated']]);
    });

    it('lists a switched-off proforma as Vypnuto and not a card-on invoice', function (): void {
        $client = CardPaymentSetup::client(['online_payment_enabled' => false]);
        $proforma = CardPaymentSetup::issued($client, InvoiceKind::Proforma);
        $card = CardPaymentSetup::issued(CardPaymentSetup::client());

        Livewire::test(ListInvoices::class)
            ->filterTable('payment_link', 'off')
            ->assertCanSeeTableRecords([$proforma])
            ->assertCanNotSeeTableRecords([$card]);
    });
});

describe('the column', function (): void {
    it('is toggleable and hidden by default', function (): void {
        linkPortfolio();

        $component = Livewire::test(ListInvoices::class);
        $column = $component->instance()->getTable()->getColumn('card_payment');

        expect($column->isToggleable())->toBeTrue()
            ->and($column->isToggledHiddenByDefault())->toBeTrue()
            ->and($component->instance()->isTableColumnToggledHidden('card_payment'))->toBeTrue()
            ->and($column->getLabel())->toBe('Platba kartou');
    });

    it('shows per row the state with a tooltip naming it', function (): void {
        $portfolio = linkPortfolio();

        $component = Livewire::test(ListInvoices::class)->toggleAllTableColumns();

        foreach (['active', 'pending', 'failed', 'deactivated', 'off'] as $state) {
            $component->assertTableColumnStateSet('card_payment', $state, $portfolio[$state]);
        }

        $component
            ->assertTableColumnStateSet('card_payment', null, $portfolio['draft'])
            ->assertTableColumnStateSet('card_payment', null, $portfolio['credit']);

        $tooltips = array_map(
            static fn (string $state): string => (string) __('payment_links.list.states.'.$state),
            ['active', 'pending', 'failed', 'deactivated', 'off'],
        );

        foreach ($tooltips as $tooltip) {
            $component->assertSeeHtml($tooltip);
        }
    });

    it('does not issue more queries for 25 rows than for 5', function (): void {
        $client = CardPaymentSetup::client();
        $count = static function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListInvoices::class)->toggleAllTableColumns();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        foreach (range(1, 5) as $unused) {
            CardPaymentSetup::issued($client);
        }

        $count();
        $five = $count();

        foreach (range(1, 20) as $unused) {
            CardPaymentSetup::issued($client);
        }

        expect($count())->toBe($five);
    });
});

describe('the Admin boundary', function (): void {
    it('refuses the list reader to a Partner and the list itself', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        expect(fn () => PaymentLinkStatus::listKeys([$invoice->id]))->toThrow(AuthorizationException::class)
            ->and(fn () => PaymentLinkStatus::for($invoice))->toThrow(AuthorizationException::class);
        Livewire::test(ListInvoices::class)->assertForbidden();
    });
});
