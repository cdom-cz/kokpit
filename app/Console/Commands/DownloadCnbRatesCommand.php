<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ExchangeRates\Actions\DownloadCnbRates;
use App\Domain\ExchangeRates\Actions\HealCnbRateGaps;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\ExchangeRates\CzechPublicHolidays;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Operations\Alerts\AdminAlerter;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Downloads today's CNB list and stores it once (EX-01, D-02).
 *
 * Exit 0 when the list was stored, was already stored, the CNB still answers an older list before the publication
 * or the day is a weekend or a public holiday (no request is made then); exit 1 when the download failed or the
 * final run of a working day ends without a list dated today. Output names dates, counts and the
 * Czech failure reason only, never the response or an exception text (T-08-13).
 *
 * After a successful daily step the run also heals gaps (HealCnbRateGaps): a Czech working day of the lookback window
 * without a stored list is fetched from the year endpoint, once a day. A failed heal fails the run like a failed
 * daily step, and the rows of the daily step stay stored. The manual download never heals.
 *
 * Run synchronously by the scheduler, never through the queue, so a dead worker cannot swallow the alert. Only the
 * `--final` run of the day alerts the Admin (D-04); the earlier attempts of the day fail quietly and wait for the
 * next hour.
 */
class DownloadCnbRatesCommand extends Command
{
    protected $signature = 'kokpit:rates:download {--final}';

    protected $description = 'Download the CNB exchange rate list of today (Europe/Prague) and store it once under the day it is valid for; --final is the last attempt of the day and alerts the Admin when the day still has no list';

    public function handle(DownloadCnbRates $action, HealCnbRateGaps $heal): int
    {
        $today = CarbonImmutable::now('Europe/Prague')->format('Y-m-d');
        $final = (bool) $this->option('final');

        // The CNB publishes on Czech working days only (assumption A5): a weekend or a public holiday makes no
        // request and raises no alert, whatever hour the scheduler fires at.
        if (! CzechPublicHolidays::isWorkingDay($today)) {
            $this->components->info(__('kokpit.rates.console.not_working_day'));

            return self::SUCCESS;
        }

        try {
            // Console work has no signed-in user, so the fail-closed scopes would silence it: the whole run is an
            // explicit system run.
            $result = app(PartnerContext::class)->runAsSystem(fn (): array => $action->handle($today));
            // Only after the daily step answered: a failed daily step throws before this line.
            $healed = app(PartnerContext::class)->runAsSystem(fn (): int => $heal->handle($today));
        } catch (CnbDownloadFailed $failure) {
            // An hourly attempt that fails is a warning: the next hour tries again and nobody is alerted.
            $line = $final ? $this->components->error(...) : $this->components->warn(...);

            $line(__('kokpit.rates.console.failed', ['reason' => $failure->failure->label()]));

            if ($failure->status !== null) {
                $line(__('kokpit.rates.console.status', ['status' => $failure->status]));
            }

            if ($final) {
                $this->alertAdmin($today);
            }

            return self::FAILURE;
        } catch (QueryException $failure) {
            // The exception stays in the application log; the alert never carries its text.
            if ($final) {
                $this->alertAdmin($today);
            }

            throw $failure;
        }

        $validFor = (string) $result['valid_for'];

        if ($result['skipped'] || ($validFor === $today && $result['inserted'] === 0)) {
            $this->components->info(__('kokpit.rates.console.already_stored', ['date' => self::display($today)]));
        } elseif ($validFor !== $today) {
            $this->components->info(__('kokpit.rates.console.not_published', ['date' => self::display($validFor)]));
        } else {
            $this->components->info(__('kokpit.rates.console.stored', [
                'date' => self::display($validFor),
                'count' => $result['inserted'],
            ]));
        }

        if ($healed > 0) {
            $this->components->info(__('kokpit.rates.console.healed', ['count' => $healed]));
        }

        // The CNB answered, but with no list dated today (it publishes late or not at all): the last run of the day
        // still tells the Admin, once.
        if ($final && ! $this->listStoredFor($today)) {
            $this->alertAdmin($today);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function listStoredFor(string $day): bool
    {
        return app(PartnerContext::class)->runAsSystem(
            static fn (): bool => ExchangeRate::query()->where('rate_date', $day)->exists(),
        );
    }

    /**
     * Tells every Admin by bell and e-mail, synchronously and throttled per day, that today's list is missing.
     * The body names two dates only: no URL, host, exception text or payload.
     */
    private function alertAdmin(string $today): void
    {
        try {
            $last = app(PartnerContext::class)->runAsSystem(
                static fn (): mixed => ExchangeRate::query()->max('rate_date'),
            );

            $body = is_string($last)
                ? __('kokpit.rates.alert.body', ['date' => self::display($today), 'last' => self::display($last)])
                : __('kokpit.rates.alert.body_none', ['date' => self::display($today)]);

            app(AdminAlerter::class)->alert('cnb-rates:'.$today, new OperationalAlert(
                __('kokpit.rates.alert.title'),
                $body,
                self::overviewUrl(),
                __('kokpit.rates.alert.action'),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The rates overview (`ExchangeRateResource`), which the alert's action opens; no action when the panel's routes
     * are not registered.
     */
    private static function overviewUrl(): ?string
    {
        return Route::has('filament.admin.resources.exchange-rates.index')
            ? route('filament.admin.resources.exchange-rates.index')
            : null;
    }

    private static function display(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->format('j. n. Y') ?? $day;
    }
}
