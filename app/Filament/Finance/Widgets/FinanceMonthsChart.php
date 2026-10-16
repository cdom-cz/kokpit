<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Queries\FinanceOverview;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The bar chart of the finance overview: income and expense of each month of one year, in whole CZK (FI-02, D-17).
 *
 * In Year mode the chart shows that year, in Month mode the year of the chosen month. The figures come from
 * {@see FinanceOverview::months()}. Chart.js needs plain numbers, so this widget is the one place where the
 * minor units of the stored CZK amounts become whole crowns (rounded half up); the tiles and tables format Money.
 * The page filters are client-controlled Livewire state and are validated again by {@see FinanceFilters}.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Income and expense by month are finance figures; a Partner is refused the page and this widget.')]
final class FinanceMonthsChart extends ChartWidget
{
    use EnforcesWidgetAccessRule;
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '20rem';

    public function getHeading(): string
    {
        return __('finance.overview.chart.heading');
    }

    /**
     * The labels and the two datasets of the chart.
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>, backgroundColor: string, borderColor: string}>}
     */
    public function chartData(): array
    {
        $state = $this->pageFilters ?? [];
        $filters = FinanceFilters::fromInput($state['period'] ?? null, $state['year'] ?? null, $state['month'] ?? null);
        $months = app(FinanceOverview::class)->months($filters->year());

        $labels = [];

        for ($month = 1; $month <= 12; $month++) {
            $labels[] = __('finance.overview.months.'.$month);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('finance.overview.chart.income'),
                    'data' => array_map(static fn (array $row): int => self::wholeCrowns($row['income_minor']), $months),
                    'backgroundColor' => 'rgba(34, 197, 94, 0.7)',
                    'borderColor' => 'rgb(34, 197, 94)',
                ],
                [
                    'label' => __('finance.overview.chart.expense'),
                    'data' => array_map(static fn (array $row): int => self::wholeCrowns($row['expense_minor']), $months),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.7)',
                    'borderColor' => 'rgb(239, 68, 68)',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        return $this->chartData();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: (value) => value.toLocaleString('cs-CZ') + ' Kč' },
                    },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (context) => context.dataset.label + ': ' + context.parsed.y.toLocaleString('cs-CZ') + ' Kč' } },
                },
            }
            JS);
    }

    /**
     * Whole crowns of a non-negative sum of minor units, rounded half up.
     */
    private static function wholeCrowns(int $minor): int
    {
        return intdiv($minor + 50, 100);
    }
}
