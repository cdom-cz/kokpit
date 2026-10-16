<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Queries\FinanceOverview;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * The clients of the finance overview: income, expense and balance per client in CZK, clients in Czech order and the
 * transactions without a client as the last row "Bez klienta" (FI-02, D-17).
 *
 * The rows come from {@see FinanceOverview::byClient()}. The page filters are client-controlled Livewire state and
 * are validated again by {@see FinanceFilters}.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Income and expense by client are finance figures; a Partner is refused the page and this widget.')]
final class FinanceClientTable extends TableWidget
{
    use EnforcesWidgetAccessRule;
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    /**
     * @return list<array{key: string, name: string, income: Money, expense: Money, balance: Money}>
     */
    public function rows(): array
    {
        $state = $this->pageFilters ?? [];
        $filters = FinanceFilters::fromInput($state['period'] ?? null, $state['year'] ?? null, $state['month'] ?? null);

        return array_map(static fn (array $row): array => [
            'key' => $row['client_id'] ?? 'none',
            'name' => $row['client_id'] === null ? __('finance.overview.clients.no_client') : $row['name'],
            'income' => Money::ofMinor($row['income_minor'], 'CZK'),
            'expense' => Money::ofMinor($row['expense_minor'], 'CZK'),
            'balance' => Money::ofMinor($row['balance_minor'], 'CZK'),
        ], app(FinanceOverview::class)->byClient($filters));
    }

    public function table(Table $table): Table
    {
        $tabular = ['style' => 'font-variant-numeric: tabular-nums;'];

        return $table
            ->heading(__('finance.overview.clients.heading'))
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('name')
                    ->label(__('finance.overview.clients.columns.client'))
                    ->state(static fn (array $record): string => $record['name']),
                TextColumn::make('income')
                    ->label(__('finance.overview.clients.columns.income'))
                    ->state(static fn (array $record): string => $record['income']->format('cs'))
                    ->alignment(Alignment::End)
                    ->extraCellAttributes($tabular),
                TextColumn::make('expense')
                    ->label(__('finance.overview.clients.columns.expense'))
                    ->state(static fn (array $record): string => $record['expense']->format('cs'))
                    ->alignment(Alignment::End)
                    ->extraCellAttributes($tabular),
                TextColumn::make('balance')
                    ->label(__('finance.overview.clients.columns.balance'))
                    ->state(static fn (array $record): string => $record['balance']->format('cs'))
                    ->color(static fn (array $record): string => $record['balance']->minor < 0 ? 'danger' : 'gray')
                    ->alignment(Alignment::End)
                    ->extraCellAttributes($tabular),
            ])
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('finance.overview.empty'));
    }
}
