<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExchangeRateResource\Widgets;

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Resources\ExchangeRateResource;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The three header stats of the rates overview, the page's answer to "are the rates current" (UI-SPEC Surface A).
 *
 * "Poslední kurzy" names the newest stored day and when it was fetched, "Stav" repeats the verdict of the
 * `last_rate_date` health slot with its colour and text (a warning and an error also carry the warning triangle,
 * so the colour is never the only carrier), and "Měn v seznamu" counts the currencies of the newest list.
 *
 * Registered as the header widget of the rates list page and not discovered at panel level, so the dashboard's
 * widget set stays what the dashboard declares.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Whether the rates are current is an operational view for the Admin; a Partner is refused the page and this widget.')]
final class ExchangeRateStats extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;

    /** The time zone every shown instant is converted to. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $newest = ExchangeRateResource::newestDate();

        return [
            $this->lastListStat($newest),
            $this->healthStat(),
            $this->currenciesStat($newest),
        ];
    }

    /**
     * The newest stored day and the moment its list was fetched, or a dash and "not downloaded yet".
     */
    private function lastListStat(?string $newest): Stat
    {
        $label = __('kokpit.rates.stats.last_list');

        if ($newest === null) {
            return Stat::make($label, (string) __('kokpit.time.empty_value'))
                ->description(__('kokpit.rates.stats.never'));
        }

        $fetchedAt = ExchangeRate::query()->where('rate_date', $newest)->max('fetched_at');

        $stat = Stat::make($label, self::display($newest));

        if (! is_string($fetchedAt) || $fetchedAt === '') {
            return $stat;
        }

        return $stat->description(__('kokpit.rates.stats.fetched_at', [
            'datetime' => CarbonImmutable::parse($fetchedAt)->setTimezone(self::DISPLAY_TIMEZONE)->format('j. n. Y H:i'),
        ]));
    }

    /**
     * The verdict of the `last_rate_date` slot: its label as the value, its detail text as the description in the
     * colour of the status, with the warning triangle for a warning and an error.
     */
    private function healthStat(): Stat
    {
        $result = app(HealthIndicatorRegistry::class)->results()[HealthSlot::LastRateDate->value] ?? null;

        $stat = Stat::make(__('kokpit.rates.stats.status'), $result?->status->getLabel() ?? HealthStatus::NotAvailable->getLabel());

        if ($result === null) {
            return $stat->descriptionColor(HealthStatus::NotAvailable->getColor());
        }

        $stat->description($result->detail)->descriptionColor($result->status->getColor());

        if ($result->status === HealthStatus::Warning || $result->status === HealthStatus::Error) {
            $stat->descriptionIcon(Heroicon::OutlinedExclamationTriangle);
        }

        return $stat;
    }

    /**
     * How many currencies the newest list holds.
     */
    private function currenciesStat(?string $newest): Stat
    {
        $count = $newest === null ? 0 : ExchangeRate::query()->where('rate_date', $newest)->count();

        return Stat::make(__('kokpit.rates.stats.currencies'), (string) $count)
            ->description(__('kokpit.rates.stats.currencies_description'));
    }

    /**
     * A `Y-m-d` day as `j. n. Y`.
     */
    private static function display(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->format('j. n. Y') ?? $day;
    }
}
