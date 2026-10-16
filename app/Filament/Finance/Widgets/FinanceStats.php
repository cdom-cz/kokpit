<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Queries\FinanceOverview;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The three tiles of the finance overview: income, expense and balance of the chosen period in CZK (FI-02, D-17).
 *
 * The figures come from {@see FinanceOverview} and are the sums of the stored CZK amounts. The page filters are
 * client-controlled Livewire state, so they are validated again by {@see FinanceFilters} and a forged value shows
 * the current Prague month. The balance is coloured by its sign and carries an arrow, so the colour is never the
 * only carrier.
 *
 * Placed on the overview page and not discovered at panel level, so the dashboard keeps its own widgets.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Income, expense and balance are finance figures; a Partner is refused the page and this widget.')]
final class FinanceStats extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $state = $this->pageFilters ?? [];
        $filters = FinanceFilters::fromInput($state['period'] ?? null, $state['year'] ?? null, $state['month'] ?? null);
        $totals = app(FinanceOverview::class)->totals($filters);
        $balance = $totals['balance'];

        return [
            Stat::make(__('finance.overview.tiles.income'), $totals['income']->format('cs'))
                ->description($filters->label())
                ->descriptionIcon(Heroicon::OutlinedArrowDownTray)
                ->color('success'),
            Stat::make(__('finance.overview.tiles.expense'), $totals['expense']->format('cs'))
                ->description($filters->label())
                ->descriptionIcon(Heroicon::OutlinedArrowUpTray)
                ->color('danger'),
            Stat::make(__('finance.overview.tiles.balance'), $balance->format('cs'))
                ->description($filters->label())
                ->descriptionIcon($balance->minor < 0 ? Heroicon::OutlinedArrowTrendingDown : Heroicon::OutlinedArrowTrendingUp)
                ->color($balance->minor < 0 ? 'danger' : 'success'),
        ];
    }
}
