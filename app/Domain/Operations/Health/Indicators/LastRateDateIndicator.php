<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\ExchangeRates\CzechPublicHolidays;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Shared\Auth\PartnerContext;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;

/**
 * How stale the newest stored CNB list is (D-04, EX-02), measured in Czech working days.
 *
 * The list the calendar expects is today's from the final attempt hour on a working day and the previous working
 * day otherwise (CzechPublicHolidays::expectedListDate); the lag is the number of working days after the newest
 * stored list up to and including that date. A weekend or a public holiday publishes no list, so a fixed count of
 * calendar days would show a false Warning after a long weekend and a false Ok after a real outage.
 *
 * No lag is Ok, kokpit.rates.health.warning_after_working_days is Warning, kokpit.rates.health.error_after_working_days
 * (never below the warning one) is Error. Never downloaded is Error, not a missing measurement. The detail is Czech
 * copy only and never carries exception text. The last known rate keeps being used whatever this slot says.
 */
final readonly class LastRateDateIndicator implements HealthIndicator
{
    public function slot(): HealthSlot
    {
        return HealthSlot::LastRateDate;
    }

    public function check(): HealthResult
    {
        // The System page is Admin-only and the registry may also run outside a request, so the read is explicit.
        $newest = app(PartnerContext::class)->runAsSystem(
            static fn (): mixed => ExchangeRate::query()->max('rate_date'),
        );

        if (! is_string($newest) || $newest === '') {
            return new HealthResult(HealthStatus::Error, null, __('kokpit.system.last_rate_date.never'));
        }

        $newest = substr($newest, 0, 10);
        $value = CarbonImmutable::parse($newest, 'UTC')->format(LocalisationServiceProvider::DATE_FORMAT);

        $expected = CzechPublicHolidays::expectedListDate(now(), (int) config('kokpit.rates.final_hour'));
        $lag = CzechPublicHolidays::workingDaysBetween($newest, $expected);

        $warningAfter = max(1, (int) config('kokpit.rates.health.warning_after_working_days'));
        $errorAfter = max($warningAfter, (int) config('kokpit.rates.health.error_after_working_days'));

        if ($lag >= $errorAfter) {
            return new HealthResult(HealthStatus::Error, $value, trans_choice('kokpit.system.last_rate_date.error', $lag, ['count' => $lag]));
        }

        if ($lag >= $warningAfter) {
            return new HealthResult(HealthStatus::Warning, $value, trans_choice('kokpit.system.last_rate_date.warning', $lag, ['count' => $lag]));
        }

        return new HealthResult(HealthStatus::Ok, $value, __('kokpit.system.last_rate_date.ok'));
    }
}
