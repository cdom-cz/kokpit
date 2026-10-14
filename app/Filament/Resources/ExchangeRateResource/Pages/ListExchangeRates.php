<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExchangeRateResource\Pages;

use App\Domain\ExchangeRates\Actions\DownloadCnbRates;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ExchangeRateResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * The rates overview with its manual download (EX-01, D-05; UI-SPEC Surface A).
 *
 * "Stáhnout dnešní kurzy" runs the very action the scheduler runs, synchronously in the request: one CNB request,
 * stored once. A manual run is never gated by the working-day calendar and never alerts the Admin.
 */
final class ListExchangeRates extends ListRecords
{
    protected static string $resource = ExchangeRateResource::class;

    public function getTitle(): string
    {
        return __('kokpit.rates.navigation');
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->downloadTodayAction(),
        ];
    }

    public function downloadTodayAction(): Action
    {
        return Action::make('downloadToday')
            ->label(__('kokpit.rates.actions.download'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->action(function (): void {
                abort_unless(ExchangeRateResource::canAccess(), 403);

                $today = CarbonImmutable::now('Europe/Prague')->format('Y-m-d');

                // The stored-day check reads a table whose scope is closed without an explicit context, like
                // the scheduled run: the whole run is an explicit system run.
                $result = app(PartnerContext::class)->runAsSystem(
                    static fn (): array => app(DownloadCnbRates::class)->handle($today),
                );

                if ($result['inserted'] > 0 && $result['valid_for'] === $today) {
                    Notification::make()
                        ->success()
                        ->title(__('kokpit.rates.download.stored.title'))
                        ->body(__('kokpit.rates.download.stored.body', [
                            'date' => self::display($today),
                            'count' => $result['inserted'],
                        ]))
                        ->send();
                }
            });
    }

    /**
     * A `Y-m-d` day as `j. n. Y`.
     */
    private static function display(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->format('j. n. Y') ?? $day;
    }
}
