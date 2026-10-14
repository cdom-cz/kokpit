<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Indicators\LastRateDateIndicator;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;

/*
 * The last rate date slot of the System page (D-04, EX-02): the newest stored CNB list measured against the list
 * the Czech working-day calendar expects, in working days and never in calendar days. Every rate, date and clock
 * is invented.
 */

/**
 * Freezes the clock at the given Prague local time.
 */
function lastRateClockAt(string $local): void
{
    test()->travelTo(CarbonImmutable::parse($local, 'Europe/Prague'));
}

/**
 * Stores one invented rate row dated `$day`, in the system context like the download writes it.
 */
function lastRateStored(string $day): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($day): void {
        ExchangeRate::query()->insert([
            'rate_date' => $day,
            'currency' => 'EUR',
            'unit_amount' => 1,
            'rate' => '25.125',
            'fetched_at' => $day.' 14:45:00+00',
            'created_at' => $day.' 14:45:00+00',
            'updated_at' => $day.' 14:45:00+00',
        ]);
    });
}

it('answers Ok with the date of today list on a working evening once today list is stored', function (): void {
    lastRateClockAt('2026-10-14 19:00:00');
    lastRateStored('2026-10-14');

    $result = (new LastRateDateIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('14. 10. 2026')
        ->and($result->detail)->toBe('Kurzy jsou aktuální.')
        ->and((new LastRateDateIndicator)->slot())->toBe(HealthSlot::LastRateDate);
});

it('is the indicator the application registry runs for the slot', function (): void {
    lastRateClockAt('2026-10-14 19:00:00');
    lastRateStored('2026-10-14');

    $registry = app(HealthIndicatorRegistry::class);

    expect($registry->indicators()[HealthSlot::LastRateDate->value])->toBeInstanceOf(LastRateDateIndicator::class)
        ->and($registry->results()[HealthSlot::LastRateDate->value]->status)->toBe(HealthStatus::Ok);
});

it('answers Ok with the newest list before the final hour of a working day', function (): void {
    lastRateClockAt('2026-10-14 16:00:00');
    lastRateStored('2026-10-13');

    expect((new LastRateDateIndicator)->check()->status)->toBe(HealthStatus::Ok);
});

it('answers Warning with one missing working day after the final hour', function (): void {
    lastRateClockAt('2026-10-14 18:30:00');
    lastRateStored('2026-10-13');

    $result = (new LastRateDateIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->value)->toBe('13. 10. 2026')
        ->and($result->detail)->toBe('Chybí kurzy za 1 pracovní den.');
});

it('answers Error with two missing working days', function (): void {
    lastRateClockAt('2026-10-14 18:30:00');
    lastRateStored('2026-10-12');

    $result = (new LastRateDateIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toBe('Kurzy chybí už 2 pracovní dny.');
});

it('counts a normal weekend as no gap on Monday morning, and a missing Friday as a Warning', function (): void {
    lastRateClockAt('2026-10-12 09:00:00');
    lastRateStored('2026-10-09');

    expect((new LastRateDateIndicator)->check()->status)->toBe(HealthStatus::Ok);

    app(PartnerContext::class)->runAsSystem(static fn () => ExchangeRate::query()->delete());
    lastRateStored('2026-10-08');

    expect((new LastRateDateIndicator)->check()->status)->toBe(HealthStatus::Warning);
});

it('raises no false alarm on the Tuesday after Easter with the list of the Thursday before', function (): void {
    lastRateClockAt('2026-04-07 09:00:00');
    lastRateStored('2026-04-02');

    expect((new LastRateDateIndicator)->check()->status)->toBe(HealthStatus::Ok);
});

it('answers Error with the never-downloaded text and no value when no list was stored', function (): void {
    lastRateClockAt('2026-10-14 19:00:00');

    $result = (new LastRateDateIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBeNull()
        ->and($result->detail)->toBe('Kurzy ještě nebyly staženy. Stáhněte je v přehledu Kurzy ČNB.');
});

it('takes the thresholds from the configuration and never lets the error threshold sit below the warning one', function (): void {
    lastRateClockAt('2026-10-14 18:30:00');
    lastRateStored('2026-10-12');
    config(['kokpit.rates.health.error_after_working_days' => 3]);

    $result = (new LastRateDateIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->detail)->toBe('Chybí kurzy za 2 pracovní dny.');

    config(['kokpit.rates.health.warning_after_working_days' => 2, 'kokpit.rates.health.error_after_working_days' => 1]);

    expect((new LastRateDateIndicator)->check()->status)->toBe(HealthStatus::Error);
});
