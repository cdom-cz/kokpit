<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\FinanceFilters;
use App\Domain\Finance\Queries\FinanceOverview;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * The categories of the finance overview: income categories first, then expense categories, each parent with the
 * sum of its children and the children listed under it with their own sums (FI-02; D-15, D-17).
 *
 * The rows come from {@see FinanceOverview::byCategory()}. The page filters are client-controlled Livewire state and
 * are validated again by {@see FinanceFilters}. A parent is bold, a child is indented and marked with a chevron, and
 * every row names its type in words, so the colour of the amount is never the only carrier.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Income and expense by category are finance figures; a Partner is refused the page and this widget.')]
final class FinanceCategoryTable extends TableWidget
{
    use EnforcesWidgetAccessRule;
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    /**
     * The rows in display order, parents and their children flattened.
     *
     * @return list<array{key: string, name: string, type: string, level: int, total: Money}>
     */
    public function rows(): array
    {
        $state = $this->pageFilters ?? [];
        $filters = FinanceFilters::fromInput($state['period'] ?? null, $state['year'] ?? null, $state['month'] ?? null);
        $rows = [];

        foreach (app(FinanceOverview::class)->byCategory($filters) as $root) {
            $rows[] = ['key' => $root['id'], 'name' => $root['name'], 'type' => $root['type'], 'level' => 0, 'total' => Money::ofMinor($root['total_minor'], 'CZK')];

            foreach ($root['children'] as $child) {
                $rows[] = ['key' => $child['id'], 'name' => $child['name'], 'type' => $child['type'], 'level' => 1, 'total' => Money::ofMinor($child['total_minor'], 'CZK')];
            }
        }

        return $rows;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('finance.overview.categories.heading'))
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('type')
                    ->label(__('finance.overview.categories.columns.type'))
                    ->state(static fn (array $record): string => __('finance.overview.categories.'.$record['type']))
                    ->badge()
                    ->color(static fn (array $record): string => $record['type'] === TransactionType::Income->value ? 'success' : 'danger'),
                TextColumn::make('name')
                    ->label(__('finance.overview.categories.columns.category'))
                    ->state(static fn (array $record): string => $record['name'])
                    ->prefix(static fn (array $record): string => $record['level'] === 0 ? '' : '› ')
                    ->weight(static fn (array $record): FontWeight => $record['level'] === 0 ? FontWeight::SemiBold : FontWeight::Normal)
                    ->extraAttributes(static fn (array $record): array => $record['level'] === 0 ? [] : ['style' => 'padding-inline-start: 1.5rem;']),
                TextColumn::make('total')
                    ->label(__('finance.overview.categories.columns.total'))
                    ->state(static fn (array $record): string => $record['total']->format('cs'))
                    ->alignment(Alignment::End)
                    ->weight(static fn (array $record): FontWeight => $record['level'] === 0 ? FontWeight::SemiBold : FontWeight::Normal)
                    ->extraCellAttributes(['style' => 'font-variant-numeric: tabular-nums;']),
            ])
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('finance.overview.empty'));
    }
}
