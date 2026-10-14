<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\FixedPriceSummary;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * The effective hourly rate of fixed-price projects (RE-05; D-07, D-08; UI-SPEC B4, U-3): under the row of a
 * fixed-price project the report shows the agreed price, the hours of the whole project and what an hour of the
 * work has earned, in the client currency and in CZK at the CNB rate of the last day of the period capped at
 * today. Every name, price and rate below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague. Last month is September 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

function effectiveRateClient(string $name, string $currency = 'EUR'): Client
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create([
        'name' => $name,
        'currency' => $currency,
        'hourly_rate' => Money::fromMajor('100', $currency),
    ]));
}

/**
 * A fixed-price project written through the domain Action, so its billing row exists.
 */
function effectiveRateProject(Client $client, string $name, string $key, string $price): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => $key,
        'billing_type' => 'fixed_price',
        'hourly_rate' => null,
        'fixed_price' => $price,
    ]);
}

/**
 * A stored entry of the signed-in Admin from UTC instants; an empty end is a running entry.
 *
 * @param  array<string, mixed>  $attributes
 */
function effectiveRateEntry(Task|Project|Client $target, string $start, string $end, array $attributes = []): TimeEntry
{
    $factory = match (true) {
        $target instanceof Task => TimeEntry::factory()->forTask($target),
        $target instanceof Project => TimeEntry::factory()->forProject($target),
        default => TimeEntry::factory()->state(['client_id' => $target->id]),
    };

    return $factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

function effectiveRateRate(string $date, string $currency, string $rate, int $unit = 1): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($date, $currency, $rate, $unit): void {
        ExchangeRate::query()->insert([
            'rate_date' => $date,
            'currency' => $currency,
            'unit_amount' => $unit,
            'rate' => $rate,
            'fetched_at' => $date.' 14:45:00+00',
            'created_at' => $date.' 14:45:00+00',
            'updated_at' => $date.' 14:45:00+00',
        ]);
    });
}

/**
 * The visible text of a rendered page, white space (no-break spaces of the money format included) collapsed.
 */
function effectiveRateText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

function effectiveRateMoney(int $minor, string $currency): string
{
    return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', Money::ofMinor($minor, $currency)->format('cs'));
}

/**
 * The report page of a client for last month as visible text.
 */
function effectiveRatePage(Client $client, string $query = 'period=last_month'): string
{
    return effectiveRateText((string) test()->get('/admin/time-report?client='.$client->id.'&'.$query)->assertOk()->getContent());
}

/**
 * A EUR client with a fixed-price project of 10000.00 EUR and 80 h of work: 40 h in September (one of the four
 * entries not billable), 38 h in August and a running entry of 2 h in October (started two hours before the
 * frozen clock).
 *
 * @return array{client: Client, project: Project}
 */
function effectiveRateArrangement(): array
{
    $client = effectiveRateClient('Example Client Alpha');
    $project = effectiveRateProject($client, 'Example Project Fixed', 'FIX', '10000');

    effectiveRateRate('2026-09-30', 'EUR', '25.000');

    effectiveRateEntry($project, '2026-09-07 06:00:00', '2026-09-07 16:00:00');
    effectiveRateEntry($project, '2026-09-08 06:00:00', '2026-09-08 16:00:00', ['billable' => false]);
    effectiveRateEntry($project, '2026-09-09 06:00:00', '2026-09-09 16:00:00');
    effectiveRateEntry($project, '2026-09-10 06:00:00', '2026-09-10 16:00:00');

    effectiveRateEntry($project, '2026-08-03 06:00:00', '2026-08-03 16:00:00');
    effectiveRateEntry($project, '2026-08-04 06:00:00', '2026-08-04 16:00:00');
    effectiveRateEntry($project, '2026-08-05 06:00:00', '2026-08-05 16:00:00', ['billable' => false]);
    effectiveRateEntry($project, '2026-08-06 06:00:00', '2026-08-06 14:00:00');

    effectiveRateEntry($project, '2026-10-14 08:00:00', '');

    return ['client' => $client, 'project' => $project];
}

describe('the effective hourly rate of a fixed-price project', function (): void {
    it('shows the price, the hours of the whole project and the rate in the client currency and in CZK', function (): void {
        $set = effectiveRateArrangement();

        $text = effectiveRatePage($set['client']);

        // 10000.00 EUR over 80:00 is 125.00 EUR per hour; at 25.000 that is 250 000,00 Kč and 3 125,00 Kč per hour.
        expect($text)
            ->toContain('Example Project Fixed Pevná cena 40:00 ')
            ->toContain('Pevná cena '.effectiveRateMoney(1000000, 'EUR').' v Kč: '.effectiveRateMoney(250000 * 100, 'CZK'))
            ->toContain('Odpracováno na celém projektu 80:00 Za celou dobu projektu, ne jen za vybrané období.')
            ->toContain('Efektivní hodinová sazba '.effectiveRateMoney(12500, 'EUR').'/h v Kč: '.effectiveRateMoney(312500, 'CZK').'/h')
            ->toContain('125,00')
            ->toContain('Kurz ČNB ke dni 30. 9. 2026');
    });

    it('counts the whole project, billable or not, finished or running, as the project overview does', function (): void {
        $set = effectiveRateArrangement();

        $now = TimerClock::now();
        $filters = ReportFilters::fromInput(['client' => $set['client']->id, 'period' => 'last_month'], $now);
        $summary = TimeReport::build($filters, $now)->fixedPrice[$set['project']->id];
        $worked = app(TimeTotals::class)->forProject($set['project'], $now)['worked'];

        expect($summary['project_seconds'])->toBe($worked)
            ->and($summary['project_seconds'])->toBe(80 * 3600)
            ->and($summary['price']->equals(Money::fromMajor('10000', 'EUR')))->toBeTrue()
            ->and($summary['effective_rate']?->equals(Money::fromMajor('125', 'EUR')))->toBeTrue()
            ->and($summary['effective_rate_czk']?->equals(Money::fromMajor('3125', 'CZK')))->toBeTrue()
            ->and($summary['price_czk']?->equals(Money::fromMajor('250000', 'CZK')))->toBeTrue()
            ->and($summary['conversion_day'])->toBe('2026-09-30')
            ->and($summary['czk_missing'])->toBeFalse();
    });

    it('puts the block directly under the project row and keeps it out of the toggles', function (): void {
        $set = effectiveRateArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();

        preg_match_all('/<tr\b[^>]*>/', $html, $rows);
        $tags = $rows[0];
        $detail = array_keys(array_filter($tags, static fn (string $tag): bool => str_contains($tag, 'data-effective-rate')));

        expect($detail)->toHaveCount(1);

        // Client row, project row, then the block; the block is neither a toggle target nor a toggle.
        [$position] = $detail;
        expect($tags[$position - 1])->toContain('id="report-row-r1"')
            ->and($tags[$position])->not->toContain('id="report-row-')
            ->and($html)->not->toMatch('/aria-controls="[^"]*effective/');
    });
});

describe('the cases where the effective rate is absent or converted otherwise', function (): void {
    it('shows a dash and the zero-hours line for a project whose entries hold no worked second', function (): void {
        $client = effectiveRateClient('Example Client Alpha');
        $project = effectiveRateProject($client, 'Example Project Fixed', 'FIX', '4000');
        effectiveRateRate('2026-09-30', 'EUR', '25.000');
        // Zero-length entries: the project is in the report, but nothing has been worked on it.
        effectiveRateEntry($project, '2026-09-15 08:00:00', '2026-09-15 08:00:00');
        effectiveRateEntry($project, '2026-09-16 08:00:00', '2026-09-16 08:00:00');

        $text = effectiveRatePage($client);

        expect($text)
            ->toContain('Pevná cena '.effectiveRateMoney(400000, 'EUR').' v Kč: '.effectiveRateMoney(100000 * 100, 'CZK'))
            ->toContain('Odpracováno na celém projektu 0:00 Za celou dobu projektu, ne jen za vybrané období.')
            ->toContain('Efektivní hodinová sazba — Na projektu zatím není žádný odpracovaný čas.')
            ->not->toContain('/h');

        $now = TimerClock::now();
        $summary = TimeReport::build(ReportFilters::fromInput(['client' => $client->id, 'period' => 'last_month'], $now), $now)->fixedPrice[$project->id];

        expect($summary['project_seconds'])->toBe(0)
            ->and($summary['effective_rate'])->toBeNull()
            ->and($summary['effective_rate_czk'])->toBeNull();
    });

    it('converts a period that ends next month at the rate of today and prints today', function (): void {
        $client = effectiveRateClient('Example Client Alpha');
        $project = effectiveRateProject($client, 'Example Project Fixed', 'FIX', '1000');
        effectiveRateRate('2026-10-14', 'EUR', '25.000');
        // A list of a later day exists in the data but is not used for a price of today's period.
        effectiveRateRate('2026-10-20', 'EUR', '30.000');
        effectiveRateEntry($project, '2026-10-05 08:00:00', '2026-10-05 18:00:00');

        $text = effectiveRatePage($client, 'period=custom&from=2026-10-01&to=2026-11-30');

        // 1000.00 EUR over 10:00 is 100.00 EUR per hour; at 25.000 that is 25 000,00 Kč and 2 500,00 Kč per hour.
        expect($text)
            ->toContain('Pevná cena '.effectiveRateMoney(100000, 'EUR').' v Kč: '.effectiveRateMoney(2500000, 'CZK'))
            ->toContain('Efektivní hodinová sazba '.effectiveRateMoney(10000, 'EUR').'/h v Kč: '.effectiveRateMoney(250000, 'CZK').'/h')
            ->toContain('Kurz ČNB ke dni 14. 10. 2026')
            ->not->toContain('Kurz ČNB ke dni 30. 11. 2026');
    });

    it('converts a period that ended in the past at the rate of its last day', function (): void {
        $client = effectiveRateClient('Example Client Alpha');
        $project = effectiveRateProject($client, 'Example Project Fixed', 'FIX', '1000');
        effectiveRateRate('2026-09-18', 'EUR', '24.000');
        effectiveRateRate('2026-10-02', 'EUR', '26.000');
        effectiveRateEntry($project, '2026-09-15 08:00:00', '2026-09-15 18:00:00');

        // The last day of the period is a Saturday: the newest list not after it is the one of Friday the 18th.
        $text = effectiveRatePage($client, 'period=custom&from=2026-09-01&to=2026-09-19');

        expect($text)
            ->toContain('Pevná cena '.effectiveRateMoney(100000, 'EUR').' v Kč: '.effectiveRateMoney(2400000, 'CZK'))
            ->toContain('Kurz ČNB ke dni 19. 9. 2026');
    });

    it('shows a CZK fixed price and its rate without CZK lines, the rate day or a badge', function (): void {
        $client = effectiveRateClient('Example Client Delta', 'CZK');
        $project = effectiveRateProject($client, 'Example Project Fixed', 'FIX', '6000');
        effectiveRateEntry($project, '2026-09-15 08:00:00', '2026-09-15 18:00:00');

        $text = effectiveRatePage($client);

        expect($text)
            ->toContain('Pevná cena '.effectiveRateMoney(600000, 'CZK').' Odpracováno na celém projektu 10:00')
            ->toContain('Efektivní hodinová sazba '.effectiveRateMoney(60000, 'CZK').'/h')
            ->not->toContain('Kurz ČNB ke dni')
            ->not->toContain('Chybí kurz')
            ->not->toContain('v Kč:');
    });

    it('marks a price in a currency without a CNB list with the badge and never shows it as zero', function (): void {
        $client = effectiveRateClient('Example Client Gamma', 'USD');
        $project = effectiveRateProject($client, 'Example Project Fixed', 'FIX', '2000');
        effectiveRateEntry($project, '2026-09-15 08:00:00', '2026-09-15 18:00:00');

        $text = effectiveRatePage($client);

        expect($text)
            ->toContain('Pevná cena '.effectiveRateMoney(200000, 'USD').' v Kč: — Chybí kurz')
            ->toContain('Efektivní hodinová sazba '.effectiveRateMoney(20000, 'USD').'/h v Kč: — Chybí kurz')
            ->not->toContain('0,00 Kč')
            ->not->toContain('Kurz ČNB ke dni');

        $now = TimerClock::now();
        $summary = TimeReport::build(ReportFilters::fromInput(['client' => $client->id, 'period' => 'last_month'], $now), $now)->fixedPrice[$project->id];

        expect($summary['czk_missing'])->toBeTrue()
            ->and($summary['price_czk'])->toBeNull()
            ->and($summary['effective_rate_czk'])->toBeNull()
            ->and($summary['effective_rate']?->equals(Money::fromMajor('200', 'USD')))->toBeTrue();
    });

    it('rounds the CZK rate once from the converted price', function (): void {
        $client = effectiveRateClient('Example Client Alpha');
        $project = effectiveRateProject($client, 'Example Project Fixed', 'FIX', '1000');
        effectiveRateRate('2026-09-30', 'EUR', '25.125');
        // 7:00 worked: 1000.00 EUR is 142.86 EUR per hour; 25 125,00 Kč over 7 h is 3 589,29 Kč per hour.
        effectiveRateEntry($project, '2026-09-15 08:00:00', '2026-09-15 15:00:00');

        $now = TimerClock::now();
        $summary = TimeReport::build(ReportFilters::fromInput(['client' => $client->id, 'period' => 'last_month'], $now), $now)->fixedPrice[$project->id];

        expect($summary['effective_rate']?->minor)->toBe(14286)
            ->and($summary['price_czk']?->minor)->toBe(2512500)
            ->and($summary['effective_rate_czk']?->minor)->toBe(358929);
    });

    it('shows the hours of a task with its own fixed price inside an hourly project without a rate block', function (): void {
        $client = effectiveRateClient('Example Client Alpha');
        $hourly = app(CreateProject::class)->handle($client, ['name' => 'Example Project Web', 'key' => 'WEB', 'billing_type' => 'hourly', 'hourly_rate' => '120']);
        $task = app(UpdateTask::class)->handle(
            test()->admin,
            app(CreateTask::class)->handle(test()->admin, $hourly, ['title' => 'Retainer'])->refresh(),
            ['billing_type' => 'fixed_price', 'fixed_price' => '1200'],
        );
        effectiveRateRate('2026-09-15', 'EUR', '25.000');
        effectiveRateEntry($task, '2026-09-15 13:00:00', '2026-09-15 13:30:00');

        $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent();
        $text = effectiveRateText($html);

        expect($text)
            ->toContain($task->reference.' · Retainer 0:30 0:30 0:00 0:00 0:30 — —')
            ->not->toContain('Odpracováno na celém projektu')
            ->not->toContain('Efektivní hodinová sazba')
            ->and($html)->not->toContain('data-effective-rate')
            ->and($html)->toContain('title="Pevná cena: částka se nepočítá za záznam."');

        $now = TimerClock::now();

        expect(TimeReport::build(ReportFilters::fromInput(['client' => $client->id, 'period' => 'last_month'], $now), $now)->fixedPrice)->toBe([]);
    });

    it('refuses everyone but the Admin and a system run', function (): void {
        [$clientId] = Canary::twoClients();
        $this->actingAs(Canary::partnerFor($clientId));
        $now = TimerClock::now();

        expect(fn (): array => FixedPriceSummary::for(['01990000-0000-7000-8000-000000000001'], ReportFilters::fromInput([], $now), $now))
            ->toThrow(AuthorizationException::class);
    });
});

describe('the cost of several fixed-price projects', function (): void {
    function effectiveRateQueryCount(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    it('runs the same number of queries for one fixed-price project and for three in two currencies', function (): void {
        $client = effectiveRateClient('Example Client Alpha');
        $first = effectiveRateProject($client, 'Example Project One', 'ONE', '1000');
        effectiveRateRate('2026-09-30', 'EUR', '25.000');
        effectiveRateRate('2026-09-30', 'USD', '22.000');
        effectiveRateEntry($first, '2026-09-15 08:00:00', '2026-09-15 10:00:00');
        $url = '/admin/time-report?period=last_month';

        // Warm the per-process caches (roles, config) once; the counts below are then comparable.
        effectiveRateQueryCount($url);
        $one = effectiveRateQueryCount($url);

        $second = effectiveRateProject($client, 'Example Project Two', 'TWO', '2000');
        effectiveRateEntry($second, '2026-09-16 08:00:00', '2026-09-16 11:00:00');
        $other = effectiveRateClient('Example Client Beta', 'USD');
        $third = effectiveRateProject($other, 'Example Project Three', 'THR', '3000');
        effectiveRateEntry($third, '2026-09-17 08:00:00', '2026-09-17 12:00:00');

        $three = effectiveRateQueryCount($url);
        $text = effectiveRateText((string) $this->get($url)->assertOk()->getContent());

        expect(substr_count($text, 'Efektivní hodinová sazba'))->toBe(3)
            ->and($three)->toBe($one);
    });
});
