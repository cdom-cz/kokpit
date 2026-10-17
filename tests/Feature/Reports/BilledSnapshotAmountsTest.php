<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Reports\ReportEntry;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

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

/**
 * Stores one invented CNB list row in the system context, as the download Action does.
 */
function snapReportRate(string $date, string $currency, string $rate): void
{
    snapReportSystem(static function () use ($date, $currency, $rate): void {
        ExchangeRate::query()->insert([
            'rate_date' => $date,
            'currency' => $currency,
            'unit_amount' => 1,
            'rate' => $rate,
            'fetched_at' => $date.' 14:45:00+00',
            'created_at' => $date.' 14:45:00+00',
            'updated_at' => $date.' 14:45:00+00',
        ]);
    });
}

/**
 * The entries of October 2026 as the reader returns them, keyed by entry id.
 *
 * @return array<string, ReportEntry>
 */
function snapReportEntries(): array
{
    $byId = [];

    foreach (app(TimeReportReader::class)->entries(ReportFilters::period('2026-10-01', '2026-10-31'), CarbonImmutable::now()) as $entry) {
        $byId[$entry->id] = $entry;
    }

    return $byId;
}

it('converts a foreign-rate billed entry at the snapshot rate and the CNB list of its work day', function (): void {
    $client = snapReportClient('EUR', '100');
    $entry = snapReportEntry($client, 3600, 5);
    snapReportRate('2026-10-05', 'EUR', '25.000');

    app(MarkEntriesBilled::class)->handle($this->admin, [$entry->id]);
    snapReportSystem(static fn (): bool => $client->forceFill(['hourly_rate' => Money::fromMajor('200', 'EUR')])->save());

    $summary = snapReportOf()->summary;

    // 1 h at the snapshot rate of 100.00 EUR = 100.00 EUR, converted at 25.000 = 2 500,00 Kč (not 5 000,00 Kč).
    expect($summary['amounts']['billed']['EUR']->equals(Money::fromMajor('100', 'EUR')))->toBeTrue()
        ->and($summary['czk']['billed']?->equals(Money::fromMajor('2500', 'CZK')))->toBeTrue()
        ->and($summary['czk_missing'])->toBe(0);
});

it('prices an entry billed before billing snapshots existed at the current rate', function (): void {
    $client = snapReportClient('CZK', '1500');
    $legacy = snapReportSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'client_id' => $client->id,
        'started_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'),
        'billing_state' => 'billed',
        'billed_at' => CarbonImmutable::parse('2026-10-06 08:00:00', 'UTC'),
    ])->refresh());
    snapReportSystem(static fn (): bool => $client->forceFill(['hourly_rate' => Money::fromMajor('2000', 'CZK')])->save());

    $entries = snapReportEntries();
    $summary = snapReportOf()->summary;

    expect($entries[$legacy->id]->billed)->toBeTrue()
        ->and($entries[$legacy->id]->billedRate)->toBeNull()
        ->and($summary['amounts']['billed']['CZK']->equals(Money::fromMajor('2000', 'CZK')))->toBeTrue();
});

it('flags a reserved entry, counts it as unbilled and leaves a billed or free one unflagged', function (): void {
    $client = snapReportClient('CZK', '1500');
    $reserved = snapReportEntry($client, 3600, 5);
    $billed = snapReportEntry($client, 1800, 6);
    $free = snapReportEntry($client, 900, 7);

    $draft = InvoiceFixtures::draft($client);
    snapReportSystem(static function () use ($reserved, $draft): void {
        DB::transaction(static function () use ($reserved, $draft): void {
            $rows = TimeEntry::query()->whereKey($reserved->id)->lockForUpdate()->get();
            app(EntryBilling::class)->reserve($rows, $draft->id);
        });
    });
    app(MarkEntriesBilled::class)->handle($this->admin, [$billed->id]);

    $entries = snapReportEntries();
    $summary = snapReportOf()->summary;

    expect($entries[$reserved->id]->reserved)->toBeTrue()
        ->and($entries[$reserved->id]->billed)->toBeFalse()
        ->and($entries[$billed->id]->reserved)->toBeFalse()
        ->and($entries[$billed->id]->billed)->toBeTrue()
        ->and($entries[$free->id]->reserved)->toBeFalse()
        // Reserved (1:00) and free (0:15) time both stay in the unbilled sum; only the billed 0:30 is billed.
        ->and($summary['unbilled'])->toBe(4500)
        ->and($summary['billed'])->toBe(1800)
        ->and($summary['amounts']['unbilled']['CZK']->equals(Money::fromMajor('1875', 'CZK')))->toBeTrue();
});

it('explains the pricing rule of billed entries in the report note', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $client = snapReportClient('CZK', '1500');
    snapReportEntry($client, 3600, 5);

    $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=this_month')->assertOk()->getContent();
    $text = (string) preg_replace('/\s+/u', ' ', strip_tags(str_replace('>', '> ', $html)));

    expect($text)
        ->toContain('Vyfakturované záznamy jsou oceněny sazbou platnou při fakturaci; u záznamů vyfakturovaných před zavedením fakturace se používá aktuální sazba.')
        ->not->toContain('Částky jsou vypočtené z aktuálních sazeb');
});
