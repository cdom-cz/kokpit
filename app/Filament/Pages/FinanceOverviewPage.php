<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Finance\FinanceFilters;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Finance\Widgets\FinanceCategoryTable;
use App\Filament\Finance\Widgets\FinanceClientTable;
use App\Filament\Finance\Widgets\FinanceMonthsChart;
use App\Filament\Finance\Widgets\FinanceStats;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The finance overview "Přehled financí": what came in and went out in one month or one year, in CZK, as tiles,
 * a category table, a by-client table and a bar chart of the months of the year (FI-02, D-17).
 *
 * Admin only: a Partner is refused at boot, before anything is read, and on every Livewire update. The Month /
 * Year switch is the stock filter form of the dashboard pages. The filter state lives in the URL and is
 * client-controlled, so the page normalises it through {@see FinanceFilters} on mount and on every change, and
 * every widget validates the filters it receives again (T-09-52): a forged value falls back to the current Prague
 * month. The filters are not kept in the session, a new visit starts on the current month.
 *
 * The widgets live under `app/Filament/Finance/Widgets`, outside the panel's widget discovery, so the dashboard
 * keeps the widgets it declares; each carries its own access rule.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The finance overview shows income, expense and balance, which are closed to Partners.')]
class FinanceOverviewPage extends Page
{
    use EnforcesPageAccessRule;
    use HasFiltersForm;

    protected static ?string $slug = 'finance-overview';

    protected static ?int $navigationSort = 61;

    protected string $view = 'filament.pages.finance-overview';

    public static function getNavigationLabel(): string
    {
        return __('finance.overview.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedChartBar;
    }

    public function getTitle(): string|Htmlable
    {
        return __('finance.overview.title');
    }

    /**
     * The filters are not carried over to the next visit.
     */
    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    /**
     * Replaces a forged or missing URL state by a valid one and shows it in the form.
     */
    public function mount(): void
    {
        $this->normaliseFilters();
        $this->getFiltersForm()->fill($this->filters);
    }

    /**
     * Every change of the form is normalised again, because the Livewire state is client-controlled.
     */
    public function updatedFilters(): void
    {
        $this->normaliseFilters();
    }

    /**
     * The validated selection of this request.
     */
    public function selection(): FinanceFilters
    {
        $state = $this->filters ?? [];

        return FinanceFilters::fromInput($state['period'] ?? null, $state['year'] ?? null, $state['month'] ?? null);
    }

    public function filtersForm(Schema $schema): Schema
    {
        $currentYear = CarbonImmutable::now(FilamentTimezone::get())->year;
        $years = [];

        for ($year = $currentYear + 1; $year >= FinanceFilters::MIN_YEAR; $year--) {
            $years[$year] = (string) $year;
        }

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = __('finance.overview.months.'.$month);
        }

        return $schema->components([
            ToggleButtons::make('period')
                ->label(__('finance.overview.filters.period'))
                ->options([
                    FinanceFilters::PERIOD_MONTH => __('finance.overview.filters.period_month'),
                    FinanceFilters::PERIOD_YEAR => __('finance.overview.filters.period_year'),
                ])
                ->inline()
                ->grouped()
                ->live(),
            Select::make('year')
                ->label(__('finance.overview.filters.year'))
                ->options($years)
                ->selectablePlaceholder(false)
                ->native(false)
                ->live(),
            Select::make('month')
                ->label(__('finance.overview.filters.month'))
                ->options($months)
                ->selectablePlaceholder(false)
                ->native(false)
                ->visible(fn (Get $get): bool => $get('period') !== FinanceFilters::PERIOD_YEAR)
                ->live(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFiltersFormContentComponent(),
            $this->getWidgetsContentComponent(),
        ]);
    }

    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm');
    }

    public function getWidgetsContentComponent(): Component
    {
        return Grid::make(['default' => 1, 'lg' => 2])
            ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                FinanceStats::class,
                FinanceCategoryTable::class,
                FinanceClientTable::class,
                FinanceMonthsChart::class,
            ]));
    }

    private function normaliseFilters(): void
    {
        $this->filters = $this->selection()->toState();
    }
}
