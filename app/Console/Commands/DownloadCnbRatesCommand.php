<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ExchangeRates\Actions\DownloadCnbRates;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
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
 * Exit 0 when the list was stored, was already stored or the CNB still answers an older list (before the
 * publication, on a weekend or a holiday); exit 1 when the download failed. Output names dates, counts and the
 * Czech failure reason only, never the response or an exception text (T-08-13).
 *
 * Run synchronously by the scheduler, never through the queue, so a dead worker cannot swallow the alert. Only the
 * `--final` run of the day alerts the Admin (D-04); the earlier attempts of the day fail quietly and wait for the
 * next hour.
 */
class DownloadCnbRatesCommand extends Command
{
    protected $signature = 'kokpit:rates:download {--final}';

    protected $description = 'Download the CNB exchange rate list of today (Europe/Prague) and store it once under the day it is valid for; --final is the last attempt of the day and alerts the Admin when the day still has no list';

    public function handle(DownloadCnbRates $action): int
    {
        $today = CarbonImmutable::now('Europe/Prague')->format('Y-m-d');
        $final = (bool) $this->option('final');

        try {
            // Console work has no signed-in user, so the fail-closed scopes would silence it: the whole run is an
            // explicit system run.
            $result = app(PartnerContext::class)->runAsSystem(fn (): array => $action->handle($today));
        } catch (CnbDownloadFailed $failure) {
            $this->components->error(__('kokpit.rates.console.failed', ['reason' => $failure->failure->label()]));

            if ($failure->status !== null) {
                $this->components->error(__('kokpit.rates.console.status', ['status' => $failure->status]));
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

        return self::SUCCESS;
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
     * The rates overview (plan 08-13); the alert carries no action until that route exists.
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
