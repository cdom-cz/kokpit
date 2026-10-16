<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Queries\FinanceOverview;
use App\Domain\Shared\Money\Money;
use App\Filament\Finance\Widgets\FinanceCategoryTable;
use App\Filament\Finance\Widgets\FinanceClientTable;
use App\Filament\Finance\Widgets\FinanceMonthsChart;
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

describe('the category rollup', function (): void {
    beforeEach(function (): void {
        $software = finOverviewCategory('expense', 'Software');
        $hosting = finOverviewCategory('expense', 'Hosting', 'Software');
        $subscription = finOverviewCategory('expense', 'Předplatné', 'Software');
        $other = finOverviewCategory('income', 'Ostatní příjmy');

        finOverviewTx('expense', '2026-10-02', $hosting, 50000);
        finOverviewTx('expense', '2026-10-03', $subscription, 30000);
        finOverviewTx('expense', '2026-10-04', $software, 10000);
        finOverviewTx('income', '2026-10-05', $other, 700000);
    });

    it('rolls the sums of the children into the parent and lists the children under it', function (): void {
        $groups = app(FinanceOverview::class)->byCategory(FinanceFilters::fromInput('month', 2026, 10));
        $software = collect($groups)->firstWhere('name', 'Software');

        expect($software['type'])->toBe('expense')
            ->and($software['total_minor'])->toBe(90000)
            ->and(array_column($software['children'], 'name'))->toBe(['Předplatné', 'Hosting'])
            ->and(array_column($software['children'], 'total_minor'))->toBe([30000, 50000]);
    });

    it('keeps income and expense apart, income first, each by position', function (): void {
        $groups = app(FinanceOverview::class)->byCategory(FinanceFilters::fromInput('month', 2026, 10));

        expect(array_column($groups, 'type'))->toBe(['income', 'expense'])
            ->and(array_column($groups, 'name'))->toBe(['Ostatní příjmy', 'Software']);
    });

    it('leaves out a category without a transaction in the period', function (): void {
        $groups = app(FinanceOverview::class)->byCategory(FinanceFilters::fromInput('month', 2026, 9));

        expect($groups)->toBe([]);
    });

    it('still lists an archived category that has transactions in the period', function (): void {
        $office = finOverviewCategory('expense', 'Kancelář');
        finOverviewTx('expense', '2026-10-06', $office, 12300);
        TransactionCategory::query()->whereKey($office->getKey())->update(['archived_at' => now()]);

        $groups = app(FinanceOverview::class)->byCategory(FinanceFilters::fromInput('month', 2026, 10));

        expect(array_column($groups, 'name'))->toContain('Kancelář');
    });

    it('shows the rolled-up table with parents and children in the widget', function (): void {
        Livewire::test(FinanceCategoryTable::class, ['pageFilters' => finOverviewOctober()])
            ->assertSeeInOrder(['Ostatní příjmy', 'Software', 'Předplatné', 'Hosting'])
            ->assertSee(Money::ofMinor(90000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(50000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(30000, 'CZK')->format('cs'));
    });

    it('says so when the period has no transactions', function (): void {
        Livewire::test(FinanceCategoryTable::class, ['pageFilters' => ['period' => 'month', 'year' => 2026, 'month' => 1]])
            ->assertSee(__('finance.overview.empty'));
    });

    it('refuses a Partner the table and the category reader', function (): void {
        $partner = Canary::partnerFor(Canary::twoClients()[0]);

        Livewire::actingAs($partner)->test(FinanceCategoryTable::class)->assertForbidden();

        expect(fn () => app(FinanceOverview::class)->byCategory(FinanceFilters::fromInput('month', 2026, 10)))
            ->toThrow(AuthorizationException::class);
    });
});

describe('the by-client table', function (): void {
    beforeEach(function (): void {
        $this->clientA = Client::factory()->create(['name' => 'Čočka Example']);
        $this->clientB = Client::factory()->create(['name' => 'Chata Example']);
        $this->clientC = Client::factory()->create(['name' => 'Cihla Example']);
        $income = finOverviewCategory('income', 'Ostatní příjmy');
        $office = finOverviewCategory('expense', 'Kancelář');

        finOverviewTx('income', '2026-10-05', $income, 1000000, ['client_id' => $this->clientA->getKey()]);
        finOverviewTx('expense', '2026-10-06', $office, 200000, ['client_id' => $this->clientA->getKey()]);
        finOverviewTx('income', '2026-10-07', $income, 50000, ['client_id' => $this->clientB->getKey()]);
        finOverviewTx('income', '2026-10-08', $income, 7000);
        finOverviewTx('expense', '2026-10-09', $office, 3000);
        finOverviewTx('income', '2026-10-10', $income, 400000, ['client_id' => $this->clientC->getKey()]);
    });

    it('gives income, expense and balance per client', function (): void {
        $rows = collect(app(FinanceOverview::class)->byClient(FinanceFilters::fromInput('month', 2026, 10)));
        $a = $rows->firstWhere('client_id', $this->clientA->getKey());

        expect($a['income_minor'])->toBe(1000000)
            ->and($a['expense_minor'])->toBe(200000)
            ->and($a['balance_minor'])->toBe(800000)
            ->and($a['name'])->toBe('Čočka Example');
    });

    it('sums the transactions without a client into one row that comes last', function (): void {
        $rows = app(FinanceOverview::class)->byClient(FinanceFilters::fromInput('month', 2026, 10));
        $last = $rows[array_key_last($rows)];

        expect($last['client_id'])->toBeNull()
            ->and($last['income_minor'])->toBe(7000)
            ->and($last['expense_minor'])->toBe(3000)
            ->and($last['balance_minor'])->toBe(4000);
    });

    it('sorts the clients in Czech order', function (): void {
        $rows = app(FinanceOverview::class)->byClient(FinanceFilters::fromInput('month', 2026, 10));

        expect(array_column($rows, 'name'))->toBe(['Cihla Example', 'Čočka Example', 'Chata Example', '']);
    });

    it('shows the rows and the "Bez klienta" label in the widget', function (): void {
        Livewire::test(FinanceClientTable::class, ['pageFilters' => finOverviewOctober()])
            ->assertSeeInOrder(['Cihla Example', 'Čočka Example', 'Chata Example', 'Bez klienta'])
            ->assertSee(Money::ofMinor(800000, 'CZK')->format('cs'));
    });

    it('shows both tables on the page of the current month', function (): void {
        $this->get(FinanceOverviewPage::getUrl())
            ->assertOk()
            ->assertSee('Bez klienta')
            ->assertSee('Cihla Example')
            ->assertSee('Ostatní příjmy');
    });

    it('refuses a Partner the table and the client reader', function (): void {
        $partner = Canary::partnerFor(Canary::twoClients()[0]);

        Livewire::actingAs($partner)->test(FinanceClientTable::class)->assertForbidden();

        $this->actingAs($partner);

        expect(fn () => app(FinanceOverview::class)->byClient(FinanceFilters::fromInput('month', 2026, 10)))
            ->toThrow(AuthorizationException::class);
    });
});

describe('a month and a year', function (): void {
    it('sums January to December of the year and leaves a 2025-12-31 transaction out', function (): void {
        $income = finOverviewCategory('income', 'Ostatní příjmy');
        $office = finOverviewCategory('expense', 'Kancelář');
        $client = Client::factory()->create(['name' => 'Cihla Example']);

        finOverviewTx('income', '2025-12-31', $income, 111100, ['client_id' => $client->getKey()]);
        finOverviewTx('income', '2026-01-01', $income, 20000, ['client_id' => $client->getKey()]);
        finOverviewTx('expense', '2026-12-31', $office, 5000, ['client_id' => $client->getKey()]);
        finOverviewTx('income', '2027-01-01', $income, 99900, ['client_id' => $client->getKey()]);

        $year = FinanceFilters::fromInput('year', 2026, null);
        $overview = app(FinanceOverview::class);
        $clients = $overview->byClient($year);

        expect($clients)->toHaveCount(1)
            ->and($clients[0]['income_minor'])->toBe(20000)
            ->and($clients[0]['expense_minor'])->toBe(5000)
            ->and($overview->byCategory($year))->toHaveCount(2)
            ->and($overview->byCategory(FinanceFilters::fromInput('month', 2026, 12)))->toHaveCount(1);
    });
});

describe('the monthly chart', function (): void {
    beforeEach(function (): void {
        $income = finOverviewCategory('income', 'Ostatní příjmy');
        $office = finOverviewCategory('expense', 'Kancelář');

        finOverviewTx('income', '2026-10-05', $income, 1000050);
        finOverviewTx('income', '2026-10-25', $income, 249950);
        finOverviewTx('expense', '2026-10-14', $office, 251250);
        finOverviewTx('income', '2026-01-31', $income, 70000);
        finOverviewTx('income', '2025-03-10', $income, 333300);
    });

    it('returns twelve rows for the year, empty months as zero', function (): void {
        $months = app(FinanceOverview::class)->months(2026);

        expect($months)->toHaveCount(12)
            ->and(array_column($months, 'month'))->toBe(range(1, 12))
            ->and($months[9])->toBe(['month' => 10, 'income_minor' => 1250000, 'expense_minor' => 251250])
            ->and($months[0]['income_minor'])->toBe(70000)
            ->and($months[1])->toBe(['month' => 2, 'income_minor' => 0, 'expense_minor' => 0]);
    });

    it('refuses a year out of range and the Partner', function (): void {
        expect(fn () => app(FinanceOverview::class)->months(1999))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(FinanceOverview::class)->months(2101))->toThrow(InvalidArgumentException::class);

        $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

        expect(fn () => app(FinanceOverview::class)->months(2026))->toThrow(AuthorizationException::class);
    });

    it('is a bar chart with the Czech month labels and two datasets in whole CZK', function (): void {
        $chart = Livewire::test(FinanceMonthsChart::class, ['pageFilters' => ['period' => 'year', 'year' => 2026, 'month' => 1]])->instance();
        assert($chart instanceof FinanceMonthsChart);
        $data = $chart->chartData();

        expect($data['labels'])->toBe(['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'])
            ->and($data['datasets'])->toHaveCount(2)
            ->and($data['datasets'][0]['label'])->toBe(__('finance.overview.chart.income'))
            ->and($data['datasets'][1]['label'])->toBe(__('finance.overview.chart.expense'))
            ->and($data['datasets'][0]['data'][9])->toBe(12500)
            ->and($data['datasets'][1]['data'][9])->toBe(2513)
            ->and($data['datasets'][0]['data'][0])->toBe(700);
    });

    it('shows the year of the chosen month in Month mode', function (): void {
        $chart = Livewire::test(FinanceMonthsChart::class, ['pageFilters' => finOverviewOctober()])->instance();
        assert($chart instanceof FinanceMonthsChart);

        expect($chart->chartData()['datasets'][0]['data'][9])->toBe(12500);

        $other = Livewire::test(FinanceMonthsChart::class, ['pageFilters' => ['period' => 'month', 'year' => 2025, 'month' => 3]])->instance();
        assert($other instanceof FinanceMonthsChart);

        expect($other->chartData()['datasets'][0]['data'][2])->toBe(3333)
            ->and($other->chartData()['datasets'][0]['data'][9])->toBe(0);
    });

    it('renders on the page', function (): void {
        $this->get(FinanceOverviewPage::getUrl())->assertOk()->assertSee(__('finance.overview.chart.heading'));
    });

    it('refuses a Partner mounting the chart', function (): void {
        Livewire::actingAs(Canary::partnerFor(Canary::twoClients()[0]))->test(FinanceMonthsChart::class)->assertForbidden();
    });
});

describe('forged filters', function (): void {
    beforeEach(function (): void {
        $income = finOverviewCategory('income', 'Ostatní příjmy');

        finOverviewTx('income', '2026-10-05', $income, 1000000);
        finOverviewTx('income', '2026-09-05', $income, 555500);
    });

    it('falls back to the current Prague month for every invalid value', function (mixed $forged): void {
        $october = FinanceFilters::fromInput('month', 2026, 10);
        $filters = FinanceFilters::fromInput($forged['period'] ?? null, $forged['year'] ?? null, $forged['month'] ?? null);

        expect($filters->toState())->toBe($october->toState())
            ->and($filters->period())->toBe(['from' => '2026-10-01', 'until' => '2026-11-01']);

        Livewire::test(FinanceStats::class, ['pageFilters' => $forged])
            ->assertSee(Money::ofMinor(1000000, 'CZK')->format('cs'))
            ->assertDontSee(Money::ofMinor(555500, 'CZK')->format('cs'));
    })->with([
        'unknown period' => [['period' => 'week']],
        'year before the range' => [['period' => 'month', 'year' => '1999', 'month' => '10']],
        'year after the range' => [['period' => 'year', 'year' => 2101]],
        'month 13' => [['period' => 'month', 'year' => 2026, 'month' => '13']],
        'month 0' => [['period' => 'month', 'year' => 2026, 'month' => 0]],
        'array year' => [['period' => 'month', 'year' => ['x'], 'month' => 10]],
        'array period' => [['period' => ['month'], 'year' => 2026, 'month' => 10]],
        'float year' => [['period' => 'year', 'year' => 2026.5]],
        'text month' => [['period' => 'month', 'year' => 2026, 'month' => 'ten']],
        'nothing' => [[]],
    ]);

    it('knows the Prague month on the last evening of a UTC month', function (): void {
        $this->travelTo('2026-10-31 23:30:00');

        expect(FinanceFilters::fromInput(null, null, null)->period())->toBe(['from' => '2026-11-01', 'until' => '2026-12-01']);
    });

    it('accepts the edges of the year range and a December month', function (): void {
        expect(FinanceFilters::fromInput('year', '2000', null)->period())->toBe(['from' => '2000-01-01', 'until' => '2001-01-01'])
            ->and(FinanceFilters::fromInput('year', 2100, null)->period())->toBe(['from' => '2100-01-01', 'until' => '2101-01-01'])
            ->and(FinanceFilters::fromInput('month', 2026, 12)->period())->toBe(['from' => '2026-12-01', 'until' => '2027-01-01'])
            ->and(FinanceFilters::fromInput('month', 2026, 3)->label())->toBe('březen 2026')
            ->and(FinanceFilters::fromInput('year', 2026, null)->label())->toBe('2026');
    });

    it('replaces a forged URL state of the page by the current month', function (): void {
        Livewire::withQueryParams(['filters' => ['period' => 'week', 'year' => '1999', 'month' => '13']])
            ->test(FinanceOverviewPage::class)
            ->assertSet('filters', ['period' => 'month', 'year' => 2026, 'month' => 10]);

        Livewire::withQueryParams(['filters' => ['period' => 'month', 'year' => ['x'], 'month' => '4']])
            ->test(FinanceOverviewPage::class)
            ->assertSet('filters', ['period' => 'month', 'year' => 2026, 'month' => 10]);
    });

    it('keeps a valid URL state of the page', function (): void {
        Livewire::withQueryParams(['filters' => ['period' => 'month', 'year' => '2026', 'month' => '9']])
            ->test(FinanceOverviewPage::class)
            ->assertSet('filters', ['period' => 'month', 'year' => 2026, 'month' => 9]);
    });

    it('normalises a forged change of the form state', function (): void {
        Livewire::test(FinanceOverviewPage::class)
            ->set('filters.year', '1999')
            ->assertSet('filters', ['period' => 'month', 'year' => 2026, 'month' => 10]);
    });

    it('switches to the year and shows the year figures', function (): void {
        Livewire::test(FinanceStats::class, ['pageFilters' => ['period' => 'year', 'year' => 2026, 'month' => 10]])
            ->assertSee(Money::ofMinor(1555500, 'CZK')->format('cs'));
    });
});
