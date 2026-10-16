<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Queries\FinanceOverview;
use App\Domain\Shared\Money\Money;
use App\Filament\Finance\Widgets\FinanceStats;
use App\Filament\Pages\FinanceOverviewPage;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin-only "Přehled financí" page (FI-02, D-17): tiles, the category table, the by-client table and the
 * monthly chart over the stored CZK amounts of the transactions. Names and amounts are invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo('2026-10-16 12:00:00');
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * A seeded category by type, name and optional parent name.
 */
function finOverviewCategory(string $type, string $name, ?string $parent = null): TransactionCategory
{
    $query = TransactionCategory::query()->where('type', $type)->where('name', $name);

    if ($parent === null) {
        return $query->whereNull('parent_id')->sole();
    }

    $parentId = TransactionCategory::query()->where('type', $type)->whereNull('parent_id')->where('name', $parent)->value('id');

    return $query->where('parent_id', $parentId)->sole();
}

/**
 * Stores one transaction with its CZK figure as it would have been stored by the write Actions.
 *
 * @param  array<string, mixed>  $overrides  keys: currency, client_id, project_id, source
 */
function finOverviewTx(string $type, string $date, TransactionCategory $category, int $czkMinor, array $overrides = []): Transaction
{
    $currency = $overrides['currency'] ?? 'CZK';
    $source = $currency === 'CZK' ? CzkSource::Identity : CzkSource::Manual;
    $amountMinor = $currency === 'CZK' ? $czkMinor : 10000;

    $transaction = new Transaction;
    $transaction->forceFill([
        'type' => TransactionType::from($type),
        'transaction_date' => $date,
        'category_id' => $category->getKey(),
        'amount' => Money::ofMinor(max(1, $amountMinor), $currency),
        'czk_minor' => $czkMinor,
        'czk_source' => $source,
        'client_id' => $overrides['client_id'] ?? null,
        'project_id' => $overrides['project_id'] ?? null,
    ]);
    $transaction->save();

    return $transaction;
}

/**
 * The October 2026 page filters.
 *
 * @return array{period: string, year: int, month: int}
 */
function finOverviewOctober(): array
{
    return ['period' => 'month', 'year' => 2026, 'month' => 10];
}

describe('the tracer: the tiles of a month', function (): void {
    beforeEach(function (): void {
        $income = finOverviewCategory('income', 'Ostatní příjmy');
        $hosting = finOverviewCategory('expense', 'Hosting', 'Software');

        finOverviewTx('income', '2026-10-05', $income, 1000000);
        finOverviewTx('expense', '2026-10-14', $hosting, 251250, ['currency' => 'EUR']);
        // Outside the month: the first day of November and an income of September.
        finOverviewTx('expense', '2026-11-01', $hosting, 999900);
        finOverviewTx('income', '2026-09-30', $income, 555500);
    });

    it('shows income, expense and balance of the chosen month from the stored CZK amounts', function (): void {
        Livewire::test(FinanceStats::class, ['pageFilters' => finOverviewOctober()])
            ->assertSee(Money::ofMinor(1000000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(251250, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(748750, 'CZK')->format('cs'))
            ->assertDontSee(Money::ofMinor(999900, 'CZK')->format('cs'))
            ->assertDontSee(Money::ofMinor(555500, 'CZK')->format('cs'));
    });

    it('opens the page for the Admin on the current Prague month and shows the same figures', function (): void {
        $this->get(FinanceOverviewPage::getUrl())
            ->assertOk()
            ->assertSee(__('finance.overview.title'))
            ->assertSee(Money::ofMinor(748750, 'CZK')->format('cs'));
    });

    it('reads the totals of the reader as Money in CZK', function (): void {
        $totals = app(FinanceOverview::class)->totals(FinanceFilters::fromInput('month', 2026, 10));

        expect($totals['income']->equals(Money::ofMinor(1000000, 'CZK')))->toBeTrue()
            ->and($totals['expense']->equals(Money::ofMinor(251250, 'CZK')))->toBeTrue()
            ->and($totals['balance']->equals(Money::ofMinor(748750, 'CZK')))->toBeTrue();
    });

    it('gives a negative balance when the expenses are higher', function (): void {
        finOverviewTx('expense', '2026-10-20', finOverviewCategory('expense', 'Kancelář'), 2000000);

        $totals = app(FinanceOverview::class)->totals(FinanceFilters::fromInput('month', 2026, 10));

        expect($totals['balance']->minor)->toBe(1000000 - 251250 - 2000000);
    });

    it('sums a whole year when the period is the year', function (): void {
        $totals = app(FinanceOverview::class)->totals(FinanceFilters::fromInput('year', 2026, null));

        expect($totals['income']->minor)->toBe(1555500)
            ->and($totals['expense']->minor)->toBe(251250 + 999900);
    });
});

describe('the tracer: only the Admin', function (): void {
    it('refuses a Partner the page', function (): void {
        $partner = Canary::partnerFor(Canary::twoClients()[0]);

        $this->actingAs($partner)->get(FinanceOverviewPage::getUrl())->assertForbidden();
        Livewire::actingAs($partner)->test(FinanceOverviewPage::class)->assertForbidden();
    });

    it('refuses a Partner mounting the stats widget', function (): void {
        $partner = Canary::partnerFor(Canary::twoClients()[0]);

        Livewire::actingAs($partner)->test(FinanceStats::class)->assertForbidden();
    });

    it('refuses the reader to a Partner', function (): void {
        $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

        expect(fn () => app(FinanceOverview::class)->totals(FinanceFilters::fromInput('month', 2026, 10)))
            ->toThrow(AuthorizationException::class);
    });
});
