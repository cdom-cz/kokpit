<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExchangeRateResource\Pages;

use App\Domain\ExchangeRates\Actions\DownloadCnbRates;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\Jobs\BackfillCnbRates;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\ExchangeRateResource;
use App\Filament\Resources\ExchangeRateResource\Widgets\ExchangeRateStats;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
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
            $this->backfillAction(),
            $this->downloadTodayAction(),
        ];
    }

    /**
     * The three stats above the table; the widget refuses everybody but the Admin.
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [ExchangeRateStats::class];
    }

    /**
     * "Doplnit historii": queues the backfill of a day range and returns at once; the job stores the lists of the
     * covered years in the background and a rerun over the same range stores nothing new (D-05).
     */
    public function backfillAction(): Action
    {
        return Action::make('backfill')
            ->label(__('kokpit.rates.actions.backfill'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->modalHeading(__('kokpit.rates.backfill.heading'))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('kokpit.rates.backfill.submit'))
            ->modalCancelActionLabel(__('kokpit.rates.backfill.cancel'))
            ->schema([
                DatePicker::make('from')
                    ->label(__('kokpit.rates.backfill.from'))
                    ->required()
                    ->rule('date_format:Y-m-d')
                    ->rule(self::rangeRule(['order', 'earliest'])),
                DatePicker::make('to')
                    ->label(__('kokpit.rates.backfill.to'))
                    ->helperText(__('kokpit.rates.backfill.helper'))
                    ->default(static fn (): string => CarbonImmutable::now('Europe/Prague')->format('Y-m-d'))
                    ->required()
                    ->rule('date_format:Y-m-d')
                    ->rule(self::rangeRule(['future', 'span'])),
            ])
            ->action(function (array $data): void {
                abort_unless(ExchangeRateResource::canAccess(), 403);

                BackfillCnbRates::dispatch((string) $data['from'], (string) $data['to']);

                Notification::make()
                    ->info()
                    ->title(__('kokpit.rates.backfill.queued.title'))
                    ->body(__('kokpit.rates.backfill.queued.body'))
                    ->send();
            });
    }

    /**
     * The validation of the range, as the field error of the first of `$limits` the range breaks: Od carries the
     * order and the earliest day, Do the future day and the span. The limits are the job's own, so the form and the
     * queued job cannot disagree; the span error names the configured maximum.
     *
     * @param  list<string>  $limits  keys of `kokpit.rates.backfill.errors`
     */
    private static function rangeRule(array $limits): Closure
    {
        return static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $limits): void {
            $from = $get('from');
            $to = $get('to');

            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            foreach (array_intersect($limits, BackfillCnbRates::violations($from, $to)) as $limit) {
                $fail(__('kokpit.rates.backfill.errors.'.$limit, ['days' => (int) config('kokpit.rates.backfill.max_span_days')]));

                return;
            }
        };
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

                try {
                    // The stored-day check reads a table whose scope is closed without an explicit context, like
                    // the scheduled run: the whole run is an explicit system run.
                    $result = app(PartnerContext::class)->runAsSystem(
                        static fn (): array => app(DownloadCnbRates::class)->handle($today),
                    );
                } catch (CnbDownloadFailed) {
                    // A manual failure stores nothing and alerts nobody (the Admin is looking at the page). The
                    // toast is the fixed copy: neither the reason class, the status nor the host reaches it.
                    Notification::make()
                        ->danger()
                        ->title(__('kokpit.rates.download.failed.title'))
                        ->body(__('kokpit.rates.download.failed.body'))
                        ->send();

                    return;
                }

                $this->notifyOutcome($result, $today);
            });
    }

    /**
     * The toast of a download that got an answer: the list of today was already stored (info), was stored now
     * (success), or the CNB still answers an earlier day's list because it has not published today's yet (info).
     *
     * @param  array{valid_for: string|null, inserted: int, skipped: bool}  $result
     */
    private function notifyOutcome(array $result, string $today): void
    {
        $validFor = $result['valid_for'] ?? ExchangeRateResource::newestDate() ?? $today;

        // A run that inserted nothing for today lost the race to another run that stored the list meanwhile.
        if ($result['skipped'] || ($validFor === $today && $result['inserted'] === 0)) {
            Notification::make()
                ->info()
                ->title(__('kokpit.rates.download.already_stored.title'))
                ->body(__('kokpit.rates.download.already_stored.body', ['date' => self::display($today)]))
                ->send();

            return;
        }

        if ($validFor === $today) {
            Notification::make()
                ->success()
                ->title(__('kokpit.rates.download.stored.title'))
                ->body(__('kokpit.rates.download.stored.body', [
                    'date' => self::display($today),
                    'count' => $result['inserted'],
                ]))
                ->send();

            return;
        }

        Notification::make()
            ->info()
            ->title(__('kokpit.rates.download.not_published.title'))
            ->body(__('kokpit.rates.download.not_published.body', ['date' => self::display($validFor)]))
            ->send();
    }

    /**
     * A `Y-m-d` day as `j. n. Y`.
     */
    private static function display(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->format('j. n. Y') ?? $day;
    }
}
