<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ExchangeRates\Actions\DownloadCnbRates;
use App\Domain\ExchangeRates\Cnb\CnbDownloadFailed;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Downloads today's CNB list and stores it once (EX-01, D-02).
 *
 * Exit 0 when the list was stored, was already stored or the CNB still answers an older list (before the
 * publication, on a weekend or a holiday); exit 1 when the download failed. Output names dates, counts and the
 * Czech failure reason only, never the response or an exception text (T-08-13).
 */
class DownloadCnbRatesCommand extends Command
{
    protected $signature = 'kokpit:rates:download';

    protected $description = 'Download the CNB exchange rate list of today (Europe/Prague) and store it once under the day it is valid for';

    public function handle(DownloadCnbRates $action): int
    {
        $today = CarbonImmutable::now('Europe/Prague')->format('Y-m-d');

        try {
            // Console work has no signed-in user, so the fail-closed scopes would silence it: the whole run is an
            // explicit system run.
            $result = app(PartnerContext::class)->runAsSystem(fn (): array => $action->handle($today));
        } catch (CnbDownloadFailed $failure) {
            $this->components->error(__('kokpit.rates.console.failed', ['reason' => $failure->failure->label()]));

            if ($failure->status !== null) {
                $this->components->error(__('kokpit.rates.console.status', ['status' => $failure->status]));
            }

            return self::FAILURE;
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

    private static function display(string $day): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')?->format('j. n. Y') ?? $day;
    }
}
