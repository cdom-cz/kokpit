<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\ExchangeRates\RateFormat;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The CNB rates Kokpit stores, as one row per currency and published day (EX-02, D-02, D-05; UI-SPEC Surface A).
 *
 * A list page only: a stored list is immutable, so there is no create, edit, view, delete or bulk action. The
 * download action lives on the page. The Datum filter opens on the newest stored day, so the page starts on the
 * current list; clearing it shows every day.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Exchange rates feed the amounts of invoices and reports; only the Admin looks at them and fetches them.')]
final class ExchangeRateResource extends Resource
{
    use EnforcesResourceAccessRule;

    /** The time zone every shown instant is converted to. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    protected static ?string $model = ExchangeRate::class;

    protected static ?string $slug = 'exchange-rates';

    protected static ?int $navigationSort = 84;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.rates.navigation');
    }

    public static function getNavigationGroup(): string
    {
        return __('kokpit.system.navigation_group');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedBanknotes;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.rates.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.rates.plural_label');
    }

    /**
     * Stored lists are written by the download only, never through a create page.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rate_date')
                    ->label(__('kokpit.rates.columns.date'))
                    ->date('j. n. Y', 'UTC')
                    ->sortable(query: static fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('rate_date', $direction === 'desc' ? 'desc' : 'asc')
                        ->orderBy('currency')),
                TextColumn::make('currency')
                    ->label(__('kokpit.rates.columns.currency'))
                    ->weight('semibold'),
                TextColumn::make('unit_amount')
                    ->label(__('kokpit.rates.columns.unit'))
                    ->numeric(),
                TextColumn::make('rate')
                    ->label(__('kokpit.rates.columns.rate'))
                    ->formatStateUsing(static fn (string $state): string => RateFormat::display($state))
                    ->alignEnd()
                    ->extraAttributes(['style' => 'font-variant-numeric: tabular-nums;']),
                TextColumn::make('fetched_at')
                    ->label(__('kokpit.rates.columns.fetched_at'))
                    ->dateTime('j. n. Y H:i', self::DISPLAY_TIMEZONE)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('rate_date')->orderBy('currency'))
            ->defaultPaginationPageOption(30)
            ->paginated([30, 60, 120])
            ->filters([self::dateFilter()])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('kokpit.rates.empty.heading'))
            ->emptyStateDescription(__('kokpit.rates.empty.description'));
    }

    /**
     * The Datum filter: one calendar day, defaulting to the newest stored one. A value that is not a real day
     * is ignored, so a forged filter state shows every day instead of failing.
     */
    private static function dateFilter(): Filter
    {
        return Filter::make('date')
            ->label(__('kokpit.rates.filters.date'))
            ->schema([
                DatePicker::make('date')
                    ->label(__('kokpit.rates.filters.date'))
                    ->default(static fn (): ?string => self::newestDate()),
            ])
            ->query(static function (Builder $query, array $data): Builder {
                $day = PragueDay::day($data['date'] ?? null);

                return $day === null ? $query : $query->where('rate_date', $day);
            })
            ->indicateUsing(static function (array $data): ?string {
                $day = PragueDay::day($data['date'] ?? null);

                return $day === null
                    ? null
                    : __('kokpit.rates.filters.date').': '.CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->format('j. n. Y');
            });
    }

    /**
     * The newest stored rate day as `Y-m-d`, or null when nothing is stored.
     */
    public static function newestDate(): ?string
    {
        $newest = ExchangeRate::query()->max('rate_date');

        return is_string($newest) && $newest !== '' ? substr($newest, 0, 10) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExchangeRates::route('/'),
        ];
    }
}
