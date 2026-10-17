<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Tests\Support\Canary;

/*
 * The reports price a billed entry with the snapshot taken at billing and an unbilled one with the current rate
 * (TI-08, IN-02; research Open Question 9 and Pitfall 7). Every name, rate and amount below is invented.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague; every entry below lies in October 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function snapReportSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A fictional client with an hourly rate in its own currency.
 */
function snapReportClient(string $currency, string $rate): Client
{
    return snapReportSystem(static fn (): Client => Client::factory()->create([
        'currency' => $currency,
        'hourly_rate' => Money::fromMajor($rate, $currency),
    ]));
}

/**
 * A finished billable entry of the exact seconds on the day of October 2026 (UTC morning).
 */
function snapReportEntry(Client $client, int $seconds, int $day = 5): TimeEntry
{
    $start = CarbonImmutable::parse('2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 08:00:00', 'UTC');

    return snapReportSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'client_id' => $client->id,
        'started_at' => $start,
        'ended_at' => $start->addSeconds($seconds),
    ])->refresh());
}

/**
 * The report of October 2026 as the page builds it.
 */
function snapReportOf(): TimeReport
{
    return TimeReport::build(ReportFilters::period('2026-10-01', '2026-10-31'), CarbonImmutable::now());
}

it('keeps the amount of an entry billed at 1 500,00 Kč after the rate rises to 2 000,00 Kč', function (): void {
    $client = snapReportClient('CZK', '1500');
    $billed = snapReportEntry($client, 3600, 5);
    snapReportEntry($client, 3600, 6);

    app(MarkEntriesBilled::class)->handle($this->admin, [$billed->id]);
    snapReportSystem(static fn (): bool => $client->forceFill(['hourly_rate' => Money::fromMajor('2000', 'CZK')])->save());

    $summary = snapReportOf()->summary;

    // The billed hour keeps 1 500,00 Kč, the unbilled hour is priced at the new 2 000,00 Kč; the total is their sum.
    expect($summary['amounts']['billed']['CZK']->equals(Money::fromMajor('1500', 'CZK')))->toBeTrue()
        ->and($summary['amounts']['unbilled']['CZK']->equals(Money::fromMajor('2000', 'CZK')))->toBeTrue()
        ->and($summary['amounts']['billable']['CZK']->equals(Money::fromMajor('3500', 'CZK')))->toBeTrue()
        ->and($summary['czk']['billable']?->equals(Money::fromMajor('3500', 'CZK')))->toBeTrue();
});
