<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Pages\TimeReportPage;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The time report "Report času" (RE-01, RE-02; D-01, D-06, D-07): the Admin sees where the time went and what
 * it is worth, per client, project and task, in the client currency and in CZK at the CNB rate of the entry's
 * Prague day. Every name, rate and amount below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague. Last month is September 2026.
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
function timeReportSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A fictional client with a rate in its own currency.
 */
function timeReportClient(string $name, string $currency = 'EUR', string $rate = '100'): Client
{
    return timeReportSystem(static fn (): Client => Client::factory()->create([
        'name' => $name,
        'currency' => $currency,
        'hourly_rate' => Money::fromMajor($rate, $currency),
    ]));
}

/**
 * A project written through the domain Action, so its billing row exists.
 *
 * @param  array<string, mixed>  $data
 */
function timeReportProject(Client $client, string $name, string $key, array $data = []): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => $key,
        'billing_type' => 'hourly',
        ...$data,
    ]);
}

function timeReportTask(User $admin, Project $project, string $title): Task
{
    return app(CreateTask::class)->handle($admin, $project, ['title' => $title])->refresh();
}

/**
 * A stored entry of the signed-in Admin from UTC instants on the task, the project or the client.
 *
 * @param  array<string, mixed>  $attributes
 */
function timeReportEntry(Task|Project|Client $target, string $start, string $end, array $attributes = []): TimeEntry
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

/**
 * Stores one invented CNB list row in the system context, as the download Action does.
 */
function timeReportRate(string $date, string $currency, string $rate, int $unit = 1): void
{
    timeReportSystem(static function () use ($date, $currency, $rate, $unit): void {
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
 * The visible text of a rendered page: tags, icons and comments removed, white space (the no-break spaces of
 * the money format included) collapsed.
 */
function timeReportText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

/**
 * A money amount as the page prints it, white space collapsed like the page text.
 */
function timeReportMoney(int $minor, string $currency): string
{
    return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', Money::ofMinor($minor, $currency)->format('cs'));
}

/**
 * The shared arrangement: a EUR client with a project that has its own rate of 120.00 EUR, two tasks, and
 * three entries of last month, one of them on a Saturday that is priced at the rate of the Friday before.
 *
 * Tuesday 2026-09-15 1:30 on the first task at 25.000, Wednesday 2026-09-16 0:45 on the second task at 25.500,
 * Saturday 2026-09-19 1:00 on the first task at the list of Friday 2026-09-18 (24.000).
 *
 * @return array{client: Client, project: Project, first: Task, second: Task}
 */
function timeReportArrangement(): array
{
    $client = timeReportClient('Example Client Alpha');
    $project = timeReportProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
    $first = timeReportTask(test()->admin, $project, 'Landing page');
    $second = timeReportTask(test()->admin, $project, 'Contact form');

    timeReportRate('2026-09-15', 'EUR', '25.000');
    timeReportRate('2026-09-16', 'EUR', '25.500');
    timeReportRate('2026-09-18', 'EUR', '24.000');

    timeReportEntry($first, '2026-09-15 08:00:00', '2026-09-15 09:30:00');
    timeReportEntry($second, '2026-09-16 08:00:00', '2026-09-16 08:45:00');
    timeReportEntry($first, '2026-09-19 08:00:00', '2026-09-19 09:00:00');

    return ['client' => $client, 'project' => $project, 'first' => $first, 'second' => $second];
}

describe('the report of a client and a month', function (): void {
    it('shows the hours, the client-currency amount and the CZK amount per client, project and task', function (): void {
        $set = timeReportArrangement();

        $response = $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month');
        $response->assertOk();
        $text = timeReportText((string) $response->getContent());

        // The first task: 1:30 + 1:00 at 120.00 EUR = 300.00 EUR; 180.00 at 25.000 and 120.00 at 24.000 = 7 380,00 Kč.
        // The second task: 0:45 = 90.00 EUR at 25.500 = 2 295,00 Kč. The Saturday entry uses the list of Friday.
        expect($text)
            ->toContain('Report času')
            ->toContain('Přehled podle klientů a projektů')
            ->toContain('Example Client Alpha 3:15 3:15 0:00 0:00 '.timeReportMoney(39000, 'EUR').' '.timeReportMoney(967500, 'CZK'))
            ->toContain('Example Project Web 3:15 3:15 0:00 0:00 '.timeReportMoney(39000, 'EUR').' '.timeReportMoney(967500, 'CZK'))
            ->toContain($set['first']->reference.' · Landing page 2:30 2:30 0:00 0:00 '.timeReportMoney(30000, 'EUR').' '.timeReportMoney(738000, 'CZK'))
            ->toContain($set['second']->reference.' · Contact form 0:45 0:45 0:00 0:00 '.timeReportMoney(9000, 'EUR').' '.timeReportMoney(229500, 'CZK'));
    });

    it('shows the summary tiles over the whole filtered set and the footer total', function (): void {
        $set = timeReportArrangement();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Odpracováno 3:15 Fakturovatelné 3:15 Nefakturovatelné 0:00')
            ->toContain('Nevyfakturováno 3:15 '.timeReportMoney(39000, 'EUR').' v Kč: '.timeReportMoney(967500, 'CZK'))
            ->toContain('Nefakturovatelné 0:00 Bez částky')
            ->toContain('Vyfakturované záznamy jsou oceněny sazbou platnou při fakturaci; u záznamů vyfakturovaných před zavedením fakturace se používá aktuální sazba.')
            ->toContain('Celkem 3:15 3:15 0:00 0:00 '.timeReportMoney(39000, 'EUR').' '.timeReportMoney(967500, 'CZK'));
    });

    it('leaves out the entries of other clients and of other months', function (): void {
        $set = timeReportArrangement();
        $other = timeReportClient('Example Client Beta', 'EUR', '90');
        timeReportEntry($other, '2026-09-15 08:00:00', '2026-09-15 10:00:00');
        // Outside last month: the last second of 2026-09-30 in Prague is inside, 2026-10-01 00:00 Prague is not.
        timeReportEntry($set['first'], '2026-10-01 08:00:00', '2026-10-01 09:00:00');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent());

        // The client select still lists Beta; its row (2:00 worked) and its hours must not reach the report.
        expect($text)
            ->toContain('Odpracováno 3:15 ')
            ->toContain('Example Client Alpha 3:15 ')
            ->not->toContain('Example Client Beta 2:00')
            ->not->toContain('Odpracováno 4:15')
            ->not->toContain('Odpracováno 5:15');
    });

    it('counts an entry that starts in the evening of the last day of the month into that month', function (): void {
        $client = timeReportClient('Example Client Alpha');
        timeReportRate('2026-09-30', 'EUR', '25.000');
        // 2026-09-30 22:30 UTC is 2026-10-01 00:30 in Prague: October, not September.
        timeReportEntry($client, '2026-09-30 22:30:00', '2026-09-30 23:30:00');
        // 2026-09-30 21:30 UTC is 2026-09-30 23:30 in Prague: still September.
        timeReportEntry($client, '2026-09-30 21:30:00', '2026-09-30 21:50:00');

        $september = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent());
        $october = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=this_month')->assertOk()->getContent());

        expect($september)->toContain('Odpracováno 0:20 ')
            ->and($october)->toContain('Odpracováno 1:00 ');
    });

    it('counts a running entry at its elapsed time', function (): void {
        $client = timeReportClient('Example Client Alpha');
        timeReportRate('2026-10-14', 'EUR', '25.000');
        // Started 30 minutes before the frozen clock.
        timeReportEntry($client, '2026-10-14 09:30:00', '');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=this_month')->assertOk()->getContent());

        expect($text)->toContain('Odpracováno 0:30 ');
    });
});

describe('who may open the report', function (): void {
    it('refuses a Partner the page', function (): void {
        [$clientId] = Canary::twoClients();
        $this->actingAs(Canary::partnerFor($clientId));

        $this->get('/admin/time-report')->assertForbidden();
    });

    it('refuses a Partner every Livewire call of the page', function (): void {
        [$clientId] = Canary::twoClients();
        $this->actingAs(Canary::partnerFor($clientId));

        Livewire::test(TimeReportPage::class)->assertForbidden();
    });
});

describe('the summary', function (): void {
    it('keeps worked equal to billed plus unbilled plus non billable and equal to the project time totals', function (): void {
        $client = timeReportClient('Example Client Alpha', 'EUR', '100');
        $project = timeReportProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
        $task = timeReportTask($this->admin, $project, 'Landing page');
        timeReportRate('2026-09-15', 'EUR', '25.000');

        timeReportEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:30:00');
        timeReportEntry($task, '2026-09-15 10:00:00', '2026-09-15 10:45:00', ['billing_state' => 'billed', 'billed_at' => CarbonImmutable::parse('2026-09-16 08:00:00', 'UTC')]);
        timeReportEntry($task, '2026-09-15 11:00:00', '2026-09-15 11:20:00', ['billable' => false]);
        // A project-only entry still running at the frozen clock (09:00 UTC to 10:00 UTC today) counts at its elapsed time.
        timeReportEntry($project, '2026-10-14 09:00:00', '');

        $now = TimerClock::now();
        $summary = TimeReport::build(ReportFilters::period('2026-09-01', '2026-10-31', $client->id), $now)->summary;
        $totals = app(TimeTotals::class)->forProject($project, $now);

        // 1:30 unbilled + 1:00 running unbilled + 0:45 billed + 0:20 non billable.
        expect($summary['worked'])->toBe(5400 + 3600 + 2700 + 1200)
            ->and($summary['unbilled'])->toBe(5400 + 3600)
            ->and($summary['billed'])->toBe(2700)
            ->and($summary['non_billable'])->toBe(1200)
            ->and($summary['billable'])->toBe(5400 + 3600 + 2700)
            ->and($summary['worked'])->toBe($summary['billed'] + $summary['unbilled'] + $summary['non_billable'])
            ->and($summary['worked'])->toBe($totals['worked'])
            ->and($summary['billed'])->toBe($totals['billed'])
            ->and($summary['unbilled'])->toBe($totals['unbilled'])
            ->and($summary['non_billable'])->toBe($totals['non_billable']);
    });

    it('adds amounts only for billable hourly entries and keeps billable equal to billed plus unbilled', function (): void {
        $client = timeReportClient('Example Client Alpha', 'EUR', '100');
        $hourly = timeReportProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
        $fixed = timeReportProject($client, 'Example Project Fixed', 'FIX', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $nonBillableTask = timeReportTask($this->admin, $hourly, 'Internal');
        timeReportRate('2026-09-15', 'EUR', '25.000');

        // 1:00 unbilled = 120.00 EUR = 3 000,00 Kč; 0:30 billed = 60.00 EUR = 1 500,00 Kč.
        timeReportEntry($hourly, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($hourly, '2026-09-15 10:00:00', '2026-09-15 10:30:00', ['billing_state' => 'billed', 'billed_at' => CarbonImmutable::parse('2026-09-16 08:00:00', 'UTC')]);
        // Hours without an amount: a fixed-price project (2:00) and a non billable entry (0:15).
        timeReportEntry($fixed, '2026-09-15 11:00:00', '2026-09-15 13:00:00');
        timeReportEntry($nonBillableTask, '2026-09-15 14:00:00', '2026-09-15 14:15:00', ['billable' => false]);

        $summary = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30', $client->id), TimerClock::now())->summary;

        expect($summary['amounts']['unbilled']['EUR']->minor)->toBe(12000)
            ->and($summary['amounts']['billed']['EUR']->minor)->toBe(6000)
            ->and($summary['amounts']['billable']['EUR']->minor)->toBe(18000)
            ->and($summary['czk']['unbilled']?->minor)->toBe(300000)
            ->and($summary['czk']['billed']?->minor)->toBe(150000)
            ->and($summary['czk']['billable']?->minor)->toBe(450000)
            ->and($summary['fixed'])->toBe(7200)
            ->and($summary['non_billable'])->toBe(900)
            ->and($summary['czk_missing'])->toBe(0);
    });

    it('counts an entry without a CNB rate in czk_missing and leaves it out of the CZK sum', function (): void {
        $client = timeReportClient('Example Client Alpha', 'EUR', '100');
        timeReportRate('2026-09-15', 'EUR', '25.000');
        // Before the first stored list: no rate for that day.
        timeReportEntry($client, '2026-09-01 08:00:00', '2026-09-01 09:00:00');
        timeReportEntry($client, '2026-09-15 08:00:00', '2026-09-15 09:00:00');

        $summary = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30', $client->id), TimerClock::now())->summary;

        expect($summary['amounts']['unbilled']['EUR']->minor)->toBe(20000)
            ->and($summary['czk']['unbilled']?->minor)->toBe(250000)
            ->and($summary['czk_missing'])->toBe(1);
    });

    it('prices a CZK client without a lookup', function (): void {
        $client = timeReportClient('Example Client Alpha', 'CZK', '800');
        timeReportEntry($client, '2026-09-15 08:00:00', '2026-09-15 09:30:00');

        $report = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30', $client->id), TimerClock::now());

        expect($report->summary['czk']['unbilled']?->minor)->toBe(120000)
            ->and($report->summary['amounts']['unbilled']['CZK']->minor)->toBe(120000)
            ->and($report->summary['czk_missing'])->toBe(0);
    });
});

describe('the filter strip', function (): void {
    it('shows only the chosen client and keeps archived clients, projects and tasks in the report', function (): void {
        $alpha = timeReportClient('Example Client Alpha');
        $beta = timeReportClient('Example Client Beta');
        $project = timeReportProject($alpha, 'Example Project Web', 'WEB');
        $task = timeReportTask($this->admin, $project, 'Landing page');
        timeReportEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($beta, '2026-09-15 08:00:00', '2026-09-15 10:00:00');
        timeReportSystem(static function () use ($task, $project, $alpha): void {
            $task->delete();
            $project->delete();
            $alpha->delete();
        });

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$alpha->id.'&period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Example Client Alpha 1:00 ')
            ->toContain('Example Project Web 1:00 ')
            ->toContain($task->reference.' · Landing page 1:00 ')
            ->not->toContain('Example Client Beta 2:00');
    });

    it('lists an archived client marked as archived and a client without projects', function (): void {
        $active = timeReportClient('Example Client Alpha');
        $archived = timeReportClient('Example Client Beta');
        timeReportSystem(static fn () => $archived->delete());

        $text = timeReportText((string) $this->get('/admin/time-report')->assertOk()->getContent());

        expect($text)
            ->toContain('Všichni klienti')
            ->toContain('Example Client Alpha')
            ->toContain('Example Client Beta (archivovaný)')
            ->not->toContain('Example Client Alpha (archivovaný)');
    });

    it('keeps the project select disabled with the hint until a client is chosen', function (): void {
        $client = timeReportClient('Example Client Alpha');
        $project = timeReportProject($client, 'Example Project Web', 'WEB');

        $without = (string) $this->get('/admin/time-report')->assertOk()->getContent();
        $with = (string) $this->get('/admin/time-report?client='.$client->id)->assertOk()->getContent();

        preg_match('/<select[^>]*name="project"[^>]*>/', $without, $disabled);
        preg_match('/<select[^>]*name="project"[^>]*>/', $with, $enabled);

        expect($disabled[0] ?? '')->toContain('disabled')
            ->and(timeReportText($without))->toContain('Nejdřív vyberte klienta')
            ->and($enabled[0] ?? '')->not->toContain('disabled')
            ->and(timeReportText($with))->toContain('Všechny projekty')->toContain('Example Project Web')
            ->and(timeReportText($with))->not->toContain('Nejdřív vyberte klienta')
            ->and($with)->toContain('value="'.$project->id.'"');
    });

    it('submits on every change and keeps the state in the URL', function (): void {
        $html = (string) $this->get('/admin/time-report?period=last_month&split=day')->assertOk()->getContent();

        expect($html)
            ->toContain('requestSubmit')
            ->toContain('method="get"')
            ->toContain('name="period" value="last_month"')
            ->toContain('name="split" value="day"');
    });

    it('prints the active range and marks the active period', function (): void {
        $text = timeReportText((string) $this->get('/admin/time-report?period=last_month')->assertOk()->getContent());
        $html = (string) $this->get('/admin/time-report?period=last_month')->assertOk()->getContent();

        expect($text)->toContain('1. 9. 2026 – 30. 9. 2026')
            ->and(timeReportText((string) $this->get('/admin/time-report')->getContent()))->toContain('1. 10. 2026 – 31. 10. 2026')
            ->and(timeReportText((string) $this->get('/admin/time-report?period=custom&from=2026-10-05&to=2026-10-11')->getContent()))->toContain('5. 10. 2026 – 11. 10. 2026')
            ->and($html)->toContain('aria-current="true"');
    });

    it('shows the date inputs only for a custom period', function (): void {
        $custom = (string) $this->get('/admin/time-report?period=custom&from=2026-10-05&to=2026-10-11')->assertOk()->getContent();
        $preset = (string) $this->get('/admin/time-report')->assertOk()->getContent();

        expect($custom)->toContain('name="from"')->toContain('value="2026-10-05"')->toContain('name="to"')->toContain('value="2026-10-11"')
            ->and($preset)->not->toContain('name="from"');
    });

    it('says why a reversed custom range was refused and shows this month', function (): void {
        $text = timeReportText((string) $this->get('/admin/time-report?period=custom&from=2026-10-20&to=2026-10-05')->assertOk()->getContent());

        expect($text)->toContain('Datum Od nesmí být později než Do.')
            ->toContain('1. 10. 2026 – 31. 10. 2026');
    });

    it('does not show the range error for a valid range', function (): void {
        $text = timeReportText((string) $this->get('/admin/time-report?period=custom&from=2026-10-05&to=2026-10-20')->assertOk()->getContent());

        expect($text)->not->toContain('Datum Od nesmí být později než Do.');
    });

    it('falls back to the defaults for forged values of every filter', function (): void {
        $text = timeReportText((string) $this->get('/admin/time-report?client[]=x&project=abc&period[]=custom&from=2026-13-45&to=nope&split=week')->assertOk()->getContent());

        expect($text)->toContain('1. 10. 2026 – 31. 10. 2026')
            ->not->toContain('Datum Od nesmí být později než Do.');
    });

    it('drops a project of another client and keeps the client', function (): void {
        $alpha = timeReportClient('Example Client Alpha');
        $beta = timeReportClient('Example Client Beta');
        timeReportProject($alpha, 'Example Project Web', 'WEB');
        $other = timeReportProject($beta, 'Example Project Other', 'OTH');
        timeReportEntry($alpha, '2026-10-02 08:00:00', '2026-10-02 09:00:00');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$alpha->id.'&project='.$other->id)->assertOk()->getContent());

        // The foreign project is neither selected nor filtered on: the client's hour still shows.
        expect($text)->toContain('Example Client Alpha 1:00 ')->toContain('Všechny projekty');
    });
});

describe('a period without entries', function (): void {
    it('shows the tiles at 0:00 and a dash, the empty state and no tree, and keeps the strip usable', function (): void {
        $client = timeReportClient('Example Client Alpha');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Odpracováno 0:00 Fakturovatelné 0:00 Nefakturovatelné 0:00')
            ->toContain('Nevyfakturováno 0:00 —')
            ->toContain('Vyfakturováno 0:00 —')
            ->toContain('Nefakturovatelné 0:00 Bez částky')
            ->toContain('Za zvolené období nejsou žádné záznamy')
            ->toContain('Zkuste jiné období nebo jiného klienta. Do reportu se počítá i nefakturovatelný čas.')
            ->toContain('Example Client Alpha')
            ->toContain('Tento měsíc')
            ->not->toContain('Přehled podle klientů a projektů');
    });
});

describe('the Livewire calls of the page', function (): void {
    it('refuses an update of the page once the user is not the Admin', function (): void {
        [$clientId] = Canary::twoClients();
        $component = Livewire::test(TimeReportPage::class)->assertSuccessful();

        $this->actingAs(Canary::partnerFor($clientId));
        app()->forgetScopedInstances();

        $component->set('period', 'last_month')->assertForbidden();
    });

    it('reads the filters again from the properties on every request', function (): void {
        $component = Livewire::test(TimeReportPage::class)
            ->set('period', 'bogus')
            ->set('split', ['x'])
            ->set('client', 12345)
            ->assertSuccessful();

        expect($component->instance()->filters()->period)->toBe('this_month')
            ->and($component->instance()->filters()->split)->toBe('none')
            ->and($component->instance()->filters()->clientId)->toBeNull();
    });
});

/**
 * A task with entries of two users on three days of last month, for the "Rozpad" tests: the Admin is named so
 * that Czech order and binary order differ (Čapek sorts before Dvořák in Czech, after it by code point).
 *
 * Tuesday 2026-09-15: the Admin 1:00 and Čapek 0:30; Wednesday 2026-09-16: the Admin 0:30;
 * Sunday 2026-09-20: Čapek 1:00 (priced at the list of Friday 2026-09-18).
 *
 * @return array{client: Client, project: Project, task: Task}
 */
function timeReportSplitArrangement(): array
{
    test()->admin->forceFill(['name' => 'Dvořák Example'])->save();
    $other = User::factory()->create(['name' => 'Čapek Example']);

    $client = timeReportClient('Example Client Alpha');
    $project = timeReportProject($client, 'Example Project Web', 'WEB');
    $task = timeReportTask(test()->admin, $project, 'Landing page');

    timeReportRate('2026-09-15', 'EUR', '25.000');
    timeReportRate('2026-09-16', 'EUR', '25.500');
    timeReportRate('2026-09-18', 'EUR', '24.000');

    timeReportEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
    timeReportEntry($task, '2026-09-15 10:00:00', '2026-09-15 10:30:00', ['user_id' => $other->id]);
    timeReportEntry($task, '2026-09-16 08:00:00', '2026-09-16 08:30:00');
    timeReportEntry($task, '2026-09-20 08:00:00', '2026-09-20 09:00:00', ['user_id' => $other->id]);

    return ['client' => $client, 'project' => $project, 'task' => $task];
}

describe('the Rozpad breakdown below each task', function (): void {
    it('lists the days of a task in date order with their hours and amounts', function (): void {
        $set = timeReportSplitArrangement();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=day')->assertOk()->getContent());

        // 15th: 1:30 = 150.00 EUR at 25.000 = 3 750,00 Kč; 16th: 0:30 = 50.00 EUR at 25.500 = 1 275,00 Kč;
        // 20th: 1:00 = 100.00 EUR at the list of Friday, 24.000 = 2 400,00 Kč.
        expect($text)
            ->toContain('15. 9. 2026 1:30 1:30 0:00 0:00 '.timeReportMoney(15000, 'EUR').' '.timeReportMoney(375000, 'CZK'))
            ->toContain('16. 9. 2026 0:30 0:30 0:00 0:00 '.timeReportMoney(5000, 'EUR').' '.timeReportMoney(127500, 'CZK'))
            ->toContain('20. 9. 2026 1:00 1:00 0:00 0:00 '.timeReportMoney(10000, 'EUR').' '.timeReportMoney(240000, 'CZK'));

        $task = strpos($text, $set['task']->reference.' · Landing page');

        expect($task)->not->toBeFalse()
            ->and(strpos($text, '15. 9. 2026 1:30'))->toBeGreaterThan($task)
            ->and(strpos($text, '16. 9. 2026 0:30'))->toBeGreaterThan(strpos($text, '15. 9. 2026 1:30'))
            ->and(strpos($text, '20. 9. 2026 1:00'))->toBeGreaterThan(strpos($text, '16. 9. 2026 0:30'));
    });

    it('lists the users of a task in Czech alphabetical order', function (): void {
        $set = timeReportSplitArrangement();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=user')->assertOk()->getContent());

        // Čapek 0:30 + 1:00 = 1:30, the Admin 1:00 + 0:30 = 1:30; Čapek is first in Czech order.
        expect($text)
            ->toContain('Čapek Example 1:30 1:30 0:00 0:00 '.timeReportMoney(15000, 'EUR').' ')
            ->toContain('Dvořák Example 1:30 1:30 0:00 0:00 '.timeReportMoney(15000, 'EUR').' ')
            ->and(strpos($text, 'Čapek Example 1:30'))->toBeLessThan(strpos($text, 'Dvořák Example 1:30'))
            ->and(strpos($text, 'Čapek Example 1:30'))->toBeGreaterThan(strpos($text, $set['task']->reference.' · Landing page'));
    });

    it('shows no child rows without a breakdown', function (): void {
        $set = timeReportSplitArrangement();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=none')->assertOk()->getContent());

        expect($text)
            ->toContain($set['task']->reference.' · Landing page 3:00 ')
            ->not->toContain('15. 9. 2026')
            ->not->toContain('Čapek Example 1:30')
            ->not->toContain('Dvořák Example 1:30');
    });

    it('marks the active breakdown and keeps the other filters in its links', function (): void {
        $set = timeReportSplitArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=day')->assertOk()->getContent();
        $text = timeReportText($html);

        expect($text)->toContain('Rozpad Bez rozpadu Po dnech Podle uživatelů')
            ->and($html)->toContain('split=user')
            ->and($html)->toContain('client='.$set['client']->id)
            ->and(preg_match_all('/aria-current="true"/', $html))->toBe(2);
    });

    it('leaves the summary and the footer identical across the three breakdowns', function (): void {
        $set = timeReportSplitArrangement();
        $now = TimerClock::now();
        $build = static fn (string $split): TimeReport => TimeReport::build(
            ReportFilters::fromInput(['client' => $set['client']->id, 'period' => 'last_month', 'split' => $split], $now),
            $now,
        );

        $none = $build('none');
        $day = $build('day');
        $user = $build('user');

        expect($day->summary)->toEqual($none->summary)
            ->and($user->summary)->toEqual($none->summary)
            ->and($day->footer)->toEqual($none->footer)
            ->and($user->footer)->toEqual($none->footer)
            ->and($none->tree[0]['children'][0]['children'][0]['children'])->toBe([])
            ->and(array_column($day->tree[0]['children'][0]['children'][0]['children'], 'label'))->toBe(['2026-09-15', '2026-09-16', '2026-09-20'])
            ->and(array_column($user->tree[0]['children'][0]['children'][0]['children'], 'label'))->toBe(['Čapek Example', 'Dvořák Example']);
    });
});

/**
 * The tree rows of a rendered report: the `<tr>` tags in order, so a test can read which rows carry `hidden`.
 *
 * @return list<string>
 */
function timeReportRowTags(string $html): array
{
    preg_match_all('/<tr\b[^>]*>/', $html, $matches);

    return $matches[0];
}

/**
 * The tags of the toggle buttons of the tree (the page chrome has buttons of its own).
 *
 * @return list<string>
 */
function timeReportToggles(string $html): array
{
    preg_match_all('/<button\b[^>]*\sdata-expand=[^>]*>/', $html, $matches);

    return $matches[0];
}

describe('the accessible tree', function (): void {
    it('gives every expandable row a toggle button with its state, its controlled rows and its accessible name', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();
        $toggles = timeReportToggles($html);

        // The client opens one level (its project is visible), the project starts collapsed; tasks are leaves.
        expect($html)
            ->toContain('aria-label="Sbalit Example Client Alpha"')
            ->toContain('aria-label="Rozbalit Example Project Web"')
            ->toContain('data-expand="Rozbalit Example Client Alpha"')
            ->toContain('data-collapse="Sbalit Example Project Web"')
            ->toContain('type="button"')
            ->toContain('min-height: 1.5rem')
            ->and($toggles)->toHaveCount(2)
            ->and(substr_count(implode('', $toggles), 'aria-expanded="true"'))->toBe(1)
            ->and(substr_count(implode('', $toggles), 'aria-expanded="false"'))->toBe(1);

        // Every aria-controls names rows that exist in the page.
        preg_match_all('/aria-controls="(report-row-[^"]+)"/', implode('', $toggles), $controls);
        expect($controls[1])->toHaveCount(2);

        foreach ($controls[1] as $list) {
            foreach (explode(' ', $list) as $id) {
                expect($html)->toContain('id="'.$id.'"');
            }
        }
    });

    it('starts with the projects visible and the task rows carrying the hidden attribute', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();
        $rows = timeReportRowTags($html);
        $hidden = array_values(array_filter($rows, static fn (string $tag): bool => preg_match('/\shidden(?=[\s>])/', $tag) === 1));

        // Header row, client, project, two tasks and the footer: only the two task rows are hidden at first.
        expect($hidden)->toHaveCount(2)
            ->and($html)->toContain('x-bind:hidden=');
    });

    it('offers expand all and collapse all that act on the whole tree', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();

        expect(timeReportText($html))->toContain('Rozbalit vše')->toContain('Sbalit vše')
            ->and($html)->toContain('expandAll()')
            ->and($html)->toContain('collapseAll()');
    });

    it('gives a task a toggle for its day rows, hidden until it is opened', function (): void {
        $set = timeReportSplitArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=day')->assertOk()->getContent();
        $hidden = array_filter(timeReportRowTags($html), static fn (string $tag): bool => preg_match('/\shidden(?=[\s>])/', $tag) === 1);

        // Client, project and task are toggles; the task row and its three day rows start hidden.
        expect(timeReportToggles($html))->toHaveCount(3)
            ->and($hidden)->toHaveCount(4)
            ->and($html)->toContain('aria-label="Rozbalit '.$set['task']->reference.' · Landing page"');
    });

    it('files entries without a project or a task under the fixed labels, last in their group', function (): void {
        $client = timeReportClient('Example Client Alpha');
        $project = timeReportProject($client, 'Example Project Web', 'WEB');
        $task = timeReportTask($this->admin, $project, 'Landing page');
        timeReportRate('2026-09-15', 'EUR', '25.000');

        timeReportEntry($project, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($task, '2026-09-15 10:00:00', '2026-09-15 11:00:00');
        timeReportEntry($client, '2026-09-15 12:00:00', '2026-09-15 12:30:00');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Bez projektu 0:30 ')
            ->toContain('Bez úkolu 1:00 ')
            ->and(strpos($text, 'Bez úkolu'))->toBeGreaterThan(strpos($text, $task->reference.' · Landing page'))
            ->and(strpos($text, 'Bez projektu'))->toBeGreaterThan(strpos($text, 'Bez úkolu'));
    });
});

describe('the fixed-price and missing-rate markers', function (): void {
    it('shows the hours of a fixed-price project and task with a dash, the tooltip, the badge and the footnote', function (): void {
        $client = timeReportClient('Example Client Alpha', 'EUR', '100');
        $hourly = timeReportProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
        $fixed = timeReportProject($client, 'Example Project Fixed', 'FIX', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $fixedTask = app(UpdateTask::class)->handle($this->admin, timeReportTask($this->admin, $hourly, 'Retainer'), ['billing_type' => 'fixed_price', 'fixed_price' => '1200']);
        timeReportRate('2026-09-15', 'EUR', '25.000');

        timeReportEntry($hourly, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($fixed, '2026-09-15 10:00:00', '2026-09-15 12:00:00');
        timeReportEntry($fixedTask, '2026-09-15 13:00:00', '2026-09-15 13:30:00');

        $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent();
        $text = timeReportText($html);

        expect($text)
            ->toContain('Example Project Fixed Pevná cena 2:00 2:00 0:00 0:00 — —')
            ->toContain($fixedTask->reference.' · Retainer 0:30 0:30 0:00 0:00 — —')
            ->toContain('Nevyfakturováno 3:30 '.timeReportMoney(12000, 'EUR').'* v Kč: '.timeReportMoney(300000, 'CZK'))
            ->toContain('* Hodiny projektů a úkolů s pevnou cenou jsou bez částky.')
            // The badge of the project row and the label of its effective rate block (only the project, not the task, has one).
            ->and(substr_count($text, 'Pevná cena'))->toBe(2)
            ->and(substr_count($text, 'Efektivní hodinová sazba'))->toBe(1)
            ->and($html)->toContain('title="Pevná cena: částka se nepočítá za záznam."');
    });

    it('shows no marker and no footnote when no fixed-price hours exist', function (): void {
        $set = timeReportArrangement();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent());

        expect($text)->not->toContain('Pevná cena')
            ->not->toContain('pevnou cenou')
            ->not->toMatch('/(€|Kč)\*/u')
            ->not->toContain('Chybí kurz');
    });

    it('marks a CZK amount without a CNB rate with a badge and a tooltip and never shows it as zero', function (): void {
        $client = timeReportClient('Example Client Gamma', 'USD', '50');
        timeReportEntry($client, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($client, '2026-09-16 08:00:00', '2026-09-16 09:00:00');

        $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent();
        $text = timeReportText($html);

        // Client, "Bez projektu" and "Bez úkolu" rows, the footer total and the summary line each carry the badge.
        expect($text)
            ->toContain('Example Client Gamma 2:00 2:00 0:00 0:00 '.timeReportMoney(10000, 'USD').' Chybí kurz')
            ->toContain('Součet v Kč nezahrnuje 2 záznamů bez kurzu ČNB.')
            ->toContain('v Kč: — Chybí kurz')
            ->not->toContain('0,00 Kč')
            ->and(substr_count($text, 'Chybí kurz'))->toBeGreaterThanOrEqual(5)
            ->and($html)->toContain('title="Pro měnu USD a den 15. 9. 2026 není k dispozici kurz ČNB. Záznam není v součtu v Kč."');
    });

    it('keeps the CZK sum of the entries that have a rate and flags the one that has not', function (): void {
        $client = timeReportClient('Example Client Alpha', 'EUR', '100');
        timeReportRate('2026-09-15', 'EUR', '25.000');
        timeReportEntry($client, '2026-09-01 08:00:00', '2026-09-01 09:00:00');
        timeReportEntry($client, '2026-09-15 08:00:00', '2026-09-15 09:00:00');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Example Client Alpha 2:00 2:00 0:00 0:00 '.timeReportMoney(20000, 'EUR').' '.timeReportMoney(250000, 'CZK').' Chybí kurz')
            ->toContain('Součet v Kč nezahrnuje 1 záznamů bez kurzu ČNB.');
    });

    it('shows the same amount in both money columns for a CZK client, without a conversion text or a badge', function (): void {
        $client = timeReportClient('Example Client Delta', 'CZK', '800');
        timeReportEntry($client, '2026-09-15 08:00:00', '2026-09-15 09:30:00');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Example Client Delta 1:30 1:30 0:00 0:00 '.timeReportMoney(120000, 'CZK').' '.timeReportMoney(120000, 'CZK'))
            ->not->toContain('Chybí kurz')
            ->not->toContain('Součet v Kč');
    });
});

describe('the row cap', function (): void {
    /**
     * A client with five tasks of one entry each, one hour apiece.
     *
     * @return array{client: Client, tasks: list<Task>}
     */
    function timeReportFiveTasks(): array
    {
        $client = timeReportClient('Example Client Alpha');
        $project = timeReportProject($client, 'Example Project Web', 'WEB');
        timeReportRate('2026-09-15', 'EUR', '25.000');
        $tasks = [];

        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $title) {
            $tasks[] = $task = timeReportTask(test()->admin, $project, $title);
            timeReportEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        }

        return ['client' => $client, 'tasks' => $tasks];
    }

    it('renders at most the configured number of leaf rows, says so, and keeps every total', function (): void {
        $set = timeReportFiveTasks();
        $now = TimerClock::now();
        $filters = ReportFilters::period('2026-09-01', '2026-09-30', $set['client']->id);
        $uncapped = TimeReport::build($filters, $now);

        config(['kokpit.report.max_rendered_rows' => 3]);

        $report = TimeReport::build($filters, $now);
        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent());

        // The page chrome (the recent-entries panel) also lists tasks, so the tree is read on its own.
        $tree = substr($text, (int) strpos($text, 'Přehled podle klientů a projektů'), (int) strpos($text, 'Zobrazeno prvních') - (int) strpos($text, 'Přehled podle klientů a projektů'));

        expect($uncapped->capped)->toBeFalse()
            ->and($uncapped->renderedLeafCount)->toBe(5)
            ->and($report->capped)->toBeTrue()
            ->and($report->renderedLeafCount)->toBe(3)
            ->and($report->summary)->toEqual($uncapped->summary)
            ->and($report->footer)->toEqual($uncapped->footer)
            ->and($report->tree[0]['seconds'])->toEqual($uncapped->tree[0]['seconds'])
            ->and($text)->toContain('Zobrazeno prvních 3 řádků. Součty výše platí pro všechny záznamy. Zúžte období nebo použijte export.')
            ->toContain('Odpracováno 5:00 ')
            ->toContain('Celkem 5:00 5:00 0:00 0:00 ')
            ->and($tree)->toContain($set['tasks'][2]->reference.' · Three')
            ->not->toContain($set['tasks'][3]->reference.' · Four')
            ->not->toContain($set['tasks'][4]->reference.' · Five');
    });

    it('counts the day rows as the leaves of a breakdown', function (): void {
        $set = timeReportSplitArrangement();
        config(['kokpit.report.max_rendered_rows' => 2]);

        $report = TimeReport::build(ReportFilters::fromInput(['client' => $set['client']->id, 'period' => 'last_month', 'split' => 'day'], TimerClock::now()), TimerClock::now());

        expect($report->capped)->toBeTrue()
            ->and($report->renderedLeafCount)->toBe(2)
            ->and(array_column($report->tree[0]['children'][0]['children'][0]['children'], 'label'))->toBe(['2026-09-15', '2026-09-16'])
            ->and($report->tree[0]['children'][0]['children'][0]['seconds']['worked'])->toBe(10800);
    });

    it('shows no notice when everything fits', function (): void {
        $set = timeReportFiveTasks();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent());

        expect($text)->not->toContain('Zobrazeno prvních');
    });
});

describe('Prague days on the calendar edges', function (): void {
    it('puts the whole 25-hour day of the autumn change into one report day and nothing from its neighbours', function (): void {
        $client = timeReportClient('Example Client Alpha');
        timeReportRate('2026-10-23', 'EUR', '25.000');
        timeReportRate('2026-10-25', 'EUR', '25.000');

        // 2026-10-25 in Prague runs from 2026-10-24T22:00:00Z to 2026-10-25T23:00:00Z.
        timeReportEntry($client, '2026-10-24T22:30:00Z', '2026-10-24T23:00:00Z');
        timeReportEntry($client, '2026-10-25T22:59:59Z', '2026-10-25T23:00:00Z');
        timeReportEntry($client, '2026-10-24T21:59:59Z', '2026-10-24T22:00:00Z');
        timeReportEntry($client, '2026-10-25T23:00:00Z', '2026-10-25T23:15:00Z');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$client->id.'&period=custom&from=2026-10-25&to=2026-10-25&split=day')->assertOk()->getContent());
        $report = TimeReport::build(ReportFilters::period('2026-10-25', '2026-10-25', $client->id), TimerClock::now());

        // 30 minutes and 1 second belong to the day; the second before it and the second after it do not.
        expect($report->entryCount)->toBe(2)
            ->and($report->summary['worked'])->toBe(1801)
            ->and($text)->toContain('25. 10. 2026 0:30 ')
            ->not->toContain('24. 10. 2026 ')
            ->not->toContain('26. 10. 2026 ');
    });

    it('puts the whole 23-hour day of the spring change into one report day', function (): void {
        $client = timeReportClient('Example Client Alpha');

        // 2027-03-28 in Prague runs from 2027-03-27T23:00:00Z to 2027-03-28T22:00:00Z.
        timeReportEntry($client, '2027-03-27 23:00:00', '2027-03-27 23:30:00');
        timeReportEntry($client, '2027-03-28 21:59:59', '2027-03-28 22:00:00');
        timeReportEntry($client, '2027-03-27 22:59:59', '2027-03-27 23:00:00');
        timeReportEntry($client, '2027-03-28 22:00:00', '2027-03-28 22:20:00');

        $report = TimeReport::build(ReportFilters::period('2027-03-28', '2027-03-28', $client->id), TimerClock::now());
        $days = TimeReport::build(ReportFilters::fromInput(['client' => $client->id, 'period' => 'custom', 'from' => '2027-03-28', 'to' => '2027-03-28', 'split' => 'day'], TimerClock::now()), TimerClock::now());

        expect($report->entryCount)->toBe(2)
            ->and($report->summary['worked'])->toBe(1801)
            ->and(array_column($days->tree[0]['children'][0]['children'][0]['children'], 'label'))->toBe(['2027-03-28']);
    });

    it('counts an entry from 23:30 to 00:30 wholly on the day it started', function (): void {
        $set = timeReportSplitArrangement();
        // 23:30 Prague on Thursday 2026-09-17 is 21:30 UTC; it ends at 00:30 on the 18th.
        timeReportEntry($set['task'], '2026-09-17 21:30:00', '2026-09-17 22:30:00');

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=day')->assertOk()->getContent());

        expect($text)
            ->toContain('17. 9. 2026 1:00 1:00 0:00 0:00 ')
            ->not->toContain('18. 9. 2026 ');
    });

    it('counts a running entry at its elapsed time from the frozen clock and prices those seconds', function (): void {
        $client = timeReportClient('Example Client Alpha', 'EUR', '100');
        timeReportRate('2026-10-14', 'EUR', '25.000');
        // The clock is frozen at 2026-10-14 10:00 UTC; the entry started 90 minutes before.
        timeReportEntry($client, '2026-10-14 08:30:00', '');

        $report = TimeReport::build(ReportFilters::period('2026-10-01', '2026-10-31', $client->id), TimerClock::now());

        // 5400 s at 100.00 EUR/h = 150.00 EUR, at 25.000 = 3 750,00 Kč.
        expect($report->summary['worked'])->toBe(5400)
            ->and($report->summary['amounts']['unbilled']['EUR']->minor)->toBe(15000)
            ->and($report->summary['czk']['unbilled']?->minor)->toBe(375000);
    });

    it('gives the same totals when the same report is built twice at the same frozen now', function (): void {
        $set = timeReportSplitArrangement();
        timeReportEntry($set['client'], '2026-10-14 08:30:00', '');
        $now = TimerClock::now();
        $filters = ReportFilters::period('2026-09-01', '2026-10-31', $set['client']->id);

        $first = TimeReport::build($filters, $now);
        $second = TimeReport::build($filters, $now);

        expect($second->summary)->toEqual($first->summary)
            ->and($second->footer)->toEqual($first->footer)
            ->and($second->tree)->toEqual($first->tree);
    });
});

describe('several currencies in one report', function (): void {
    it('lists one amount per currency in the summary and the footer and sums only the CZK column', function (): void {
        $eur = timeReportClient('Example Client Alpha', 'EUR', '100');
        $usd = timeReportClient('Example Client Gamma', 'USD', '80');
        $czk = timeReportClient('Example Client Delta', 'CZK', '900');
        timeReportRate('2026-09-15', 'EUR', '25.000');
        timeReportRate('2026-09-15', 'USD', '22.000');

        timeReportEntry($eur, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($usd, '2026-09-15 08:00:00', '2026-09-15 10:00:00');
        timeReportEntry($czk, '2026-09-15 08:00:00', '2026-09-15 08:30:00');

        $report = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30'), TimerClock::now());
        $text = timeReportText((string) $this->get('/admin/time-report?period=last_month')->assertOk()->getContent());

        // 1:00 at 100.00 EUR = 2 500,00 Kč; 2:00 at 80.00 USD = 160.00 USD = 3 520,00 Kč; 0:30 at 900 Kč = 450,00 Kč.
        expect(array_keys($report->summary['amounts']['unbilled']))->toBe(['CZK', 'EUR', 'USD'])
            ->and($report->summary['amounts']['unbilled']['EUR']->minor)->toBe(10000)
            ->and($report->summary['amounts']['unbilled']['USD']->minor)->toBe(16000)
            ->and($report->summary['czk']['unbilled']?->minor)->toBe(250000 + 352000 + 45000)
            ->and(array_keys($report->footer['amounts']))->toBe(['CZK', 'EUR', 'USD'])
            ->and($report->footer['czk']?->minor)->toBe(647000)
            ->and($report->tree)->toHaveCount(3)
            ->and($text)
            ->toContain(timeReportMoney(10000, 'EUR').' '.timeReportMoney(16000, 'USD'))
            ->toContain('v Kč: '.timeReportMoney(647000, 'CZK'))
            ->toContain('Example Client Gamma 2:00 2:00 0:00 0:00 '.timeReportMoney(16000, 'USD').' '.timeReportMoney(352000, 'CZK'));
    });

    it('keeps the totals of the hours when a client of another currency is added', function (): void {
        $eur = timeReportClient('Example Client Alpha', 'EUR', '100');
        $usd = timeReportClient('Example Client Gamma', 'USD', '80');
        timeReportRate('2026-09-15', 'EUR', '25.000');
        timeReportRate('2026-09-15', 'USD', '22.000');
        timeReportEntry($eur, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($usd, '2026-09-15 08:00:00', '2026-09-15 10:00:00');

        $both = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30'), TimerClock::now());
        $onlyEur = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30', $eur->id), TimerClock::now());

        expect($both->summary['worked'])->toBe(10800)
            ->and($onlyEur->summary['worked'])->toBe(3600)
            ->and($both->footer['seconds']['worked'])->toBe(10800)
            ->and(array_keys($both->tree[0]['amounts']))->toBe(['EUR'])
            ->and(array_keys($both->tree[1]['amounts']))->toBe(['USD']);
    });
});

describe('a client with money in two currencies', function (): void {
    /**
     * A CZK client whose task "Landing page" still carries a rate of 120.00 EUR, and a task "Contact form" without one.
     *
     * The legacy row stands for a rate stored while the client was still in EUR; the client currency lock of plan
     * 08-25 prevents it for new data. Entries of 2026-09-15: 1:00 on Landing page (120.00 EUR, 3 000,00 Kč at 25.000)
     * and 1:30 on Contact form (1 500,00 Kč at the client rate of 1 000 Kč). The CNB rate of EUR is stored unless
     * `$withRate` is false.
     *
     * @return array{client: Client, project: Project, landing: Task, contact: Task}
     */
    function timeReportMixedClient(bool $withRate = true): array
    {
        $client = timeReportClient('Example Client Mixed', 'CZK', '1000');
        $project = timeReportProject($client, 'Example Project Web', 'WEB');
        $landing = timeReportTask(test()->admin, $project, 'Landing page');
        $contact = timeReportTask(test()->admin, $project, 'Contact form');

        timeReportSystem(static fn () => $landing->billing()->create([
            'billing_type' => TaskBillingType::Inherit,
            'hourly_rate' => Money::fromMajor('120', 'EUR'),
        ]));

        if ($withRate) {
            timeReportRate('2026-09-15', 'EUR', '25.000');
        }

        timeReportEntry($landing, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($contact, '2026-09-15 10:00:00', '2026-09-15 11:30:00');

        return ['client' => $client, 'project' => $project, 'landing' => $landing, 'contact' => $contact];
    }

    it('opens the report of the client and of all clients with one amount line per currency', function (): void {
        $set = timeReportMixedClient();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent());
        $all = timeReportText((string) $this->get('/admin/time-report?period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Example Client Mixed 2:30 2:30 0:00 0:00 '.timeReportMoney(150000, 'CZK').' '.timeReportMoney(12000, 'EUR').' '.timeReportMoney(450000, 'CZK'))
            ->toContain($set['landing']->reference.' · Landing page 1:00 1:00 0:00 0:00 '.timeReportMoney(12000, 'EUR').' '.timeReportMoney(300000, 'CZK'))
            ->toContain($set['contact']->reference.' · Contact form 1:30 1:30 0:00 0:00 '.timeReportMoney(150000, 'CZK').' '.timeReportMoney(150000, 'CZK'))
            ->and($all)->toContain('Example Client Mixed');
    });

    it('keeps the amounts of a row per currency in the aggregate and sums only the CZK column', function (): void {
        $set = timeReportMixedClient();

        $report = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30', $set['client']->id), TimerClock::now());

        expect(array_keys($report->tree[0]['amounts']))->toBe(['CZK', 'EUR'])
            ->and($report->tree[0]['amounts']['CZK']->minor)->toBe(150000)
            ->and($report->tree[0]['amounts']['EUR']->minor)->toBe(12000)
            ->and($report->tree[0]['czk']?->minor)->toBe(450000)
            ->and(array_keys($report->tree[0]['children'][0]['amounts']))->toBe(['CZK', 'EUR']);
    });

    it('lists the part without a CNB rate as missing and names the currency, never as zero', function (): void {
        timeReportMixedClient(withRate: false);

        $html = (string) $this->get('/admin/time-report?period=last_month')->assertOk()->getContent();
        $text = timeReportText($html);

        expect($text)
            ->toContain('Example Client Mixed 2:30 2:30 0:00 0:00 '.timeReportMoney(150000, 'CZK').' '.timeReportMoney(12000, 'EUR').' '.timeReportMoney(150000, 'CZK').' Chybí kurz')
            ->toContain('Součet v Kč nezahrnuje 1 záznamů bez kurzu ČNB.')
            // A zero amount, not the tail of "1 500,00 Kč".
            ->not->toMatch('/(?<!\d)0,00 Kč/u')
            ->and($html)->toContain('title="Pro měnu EUR a den 15. 9. 2026 není k dispozici kurz ČNB. Záznam není v součtu v Kč."');
    });

    it('keeps the footer equal to the billable amounts of the summary, key by key', function (): void {
        timeReportMixedClient();
        $eur = timeReportClient('Example Client Alpha', 'EUR', '100');
        $usd = timeReportClient('Example Client Gamma', 'USD', '80');
        timeReportRate('2026-09-15', 'USD', '22.000');
        timeReportEntry($eur, '2026-09-15 12:00:00', '2026-09-15 13:00:00');
        timeReportEntry($usd, '2026-09-15 12:00:00', '2026-09-15 14:00:00');

        $report = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30'), TimerClock::now());

        expect(array_keys($report->footer['amounts']))->toBe(['CZK', 'EUR', 'USD'])
            ->and(array_map(static fn (Money $money): int => $money->minor, $report->footer['amounts']))
            ->toBe(array_map(static fn (Money $money): int => $money->minor, $report->summary['amounts']['billable']))
            ->and($report->footer['czk']?->minor)->toBe($report->summary['czk']['billable']?->minor)
            // The clients come in Czech alphabetical order: Alpha, Gamma, Mixed.
            ->and(array_keys($report->tree[0]['amounts']))->toBe(['EUR'])
            ->and(array_keys($report->tree[1]['amounts']))->toBe(['USD'])
            ->and(array_keys($report->tree[2]['amounts']))->toBe(['CZK', 'EUR']);
    });

    it('still shows the hours of a fixed-price task with dashes and the tooltip', function (): void {
        $set = timeReportMixedClient();
        $fixed = app(UpdateTask::class)->handle($this->admin, timeReportTask($this->admin, $set['project'], 'Retainer'), ['billing_type' => 'fixed_price', 'fixed_price' => '500']);
        timeReportEntry($fixed, '2026-09-15 12:00:00', '2026-09-15 12:30:00');

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();
        $text = timeReportText($html);

        expect($text)
            ->toContain($fixed->reference.' · Retainer 0:30 0:30 0:00 0:00 — —')
            ->toContain('Example Client Mixed 3:00 3:00 0:00 0:00 '.timeReportMoney(150000, 'CZK').' '.timeReportMoney(12000, 'EUR').' '.timeReportMoney(450000, 'CZK'))
            ->and($html)->toContain('title="Pevná cena: částka se nepočítá za záznam."');
    });

    it('lists both currencies on the day row of the task', function (): void {
        $set = timeReportMixedClient();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=day')->assertOk()->getContent());

        expect($text)->toContain('15. 9. 2026 1:00 1:00 0:00 0:00 '.timeReportMoney(12000, 'EUR').' '.timeReportMoney(300000, 'CZK'));
    });
});

describe('the cost of a long report', function (): void {
    /**
     * The number of queries one request of the report page runs once everything is warm.
     */
    function timeReportQueryCount(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    it('runs the same number of queries for 5 entries and for 200 entries over several clients, projects and tasks', function (): void {
        $first = timeReportClient('Example Client Alpha');
        $project = timeReportProject($first, 'Example Project Web', 'WEB');
        $task = timeReportTask($this->admin, $project, 'Landing page');
        timeReportRate('2026-09-15', 'EUR', '25.000');

        foreach (range(1, 5) as $n) {
            timeReportEntry($task, '2026-09-15 0'.$n.':00:00', '2026-09-15 0'.$n.':30:00');
        }

        $urls = [
            '/admin/time-report?period=last_month',
            '/admin/time-report?period=last_month&split=day',
            '/admin/time-report?period=last_month&split=user',
        ];

        // Warm the per-process caches (roles, config) once; the counts below are then comparable.
        foreach ($urls as $url) {
            timeReportQueryCount($url);
        }

        $short = array_map(timeReportQueryCount(...), $urls);

        $second = timeReportClient('Example Client Beta', 'USD', '90');
        $other = User::factory()->create(['name' => 'Čapek Example']);
        timeReportRate('2026-09-15', 'USD', '22.000');

        foreach ([$first, $second] as $client) {
            foreach (['A', 'B'] as $suffix) {
                $key = $client->is($first) ? 'AL'.$suffix : 'BE'.$suffix;
                $extra = timeReportProject($client, 'Example Project '.$key, $key);

                foreach (['One', 'Two'] as $title) {
                    $extraTask = timeReportTask($this->admin, $extra, $title);

                    foreach (range(1, 12) as $n) {
                        timeReportEntry($extraTask, '2026-09-'.str_pad((string) (1 + ($n % 20)), 2, '0', STR_PAD_LEFT).' 08:'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).':00', '2026-09-'.str_pad((string) (1 + ($n % 20)), 2, '0', STR_PAD_LEFT).' 09:'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).':00', ['user_id' => $n % 2 === 0 ? $other->id : $this->admin->id]);
                    }
                }
            }
        }

        $long = array_map(timeReportQueryCount(...), $urls);
        $entries = TimeEntry::query()->count();

        expect($entries)->toBeGreaterThan(100)
            ->and($long)->toBe($short);
    });
});

describe('the row cap setting', function (): void {
    it('is a documented config value of 1000 rendered rows', function (): void {
        expect(config('kokpit.report.max_rendered_rows'))->toBe(1000);
    });
});

/**
 * The markup of the tree row of a name: the row tag with its id and everything up to its closing tag.
 */
function timeReportRowHtml(string $html, string $needle): string
{
    preg_match_all('#<tr\b[^>]*id="report-row-[^"]*"[^>]*>.*?</tr>#s', $html, $rows);

    foreach ($rows[0] as $row) {
        if (str_contains($row, $needle)) {
            return $row;
        }
    }

    return '';
}

describe('the readable table', function (): void {
    it('uses the full content width of the panel', function (): void {
        expect(Livewire::test(TimeReportPage::class)->instance()->getMaxContentWidth())->toBe(Width::Full);
    });

    it('lays the tree out as a fixed table with a floor and seven columns', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();

        preg_match('#<colgroup.*?</colgroup>#s', $html, $group);
        preg_match_all('#<col\b[^>]*>#', $group[0] ?? '', $cols);
        $cols = $cols[0];

        expect($html)->toContain('table-layout: fixed')->toContain('min-width: 64rem')
            ->and($cols)->toHaveCount(7)
            ->and(array_filter($cols, static fn (string $col): bool => ! str_contains($col, 'width')))->toHaveCount(1)
            ->and(array_filter($cols, static fn (string $col): bool => str_contains($col, 'width: 6rem')))->toHaveCount(4)
            ->and(array_filter($cols, static fn (string $col): bool => str_contains($col, 'width: 10rem')))->toHaveCount(2);
    });

    it('groups the four hour columns under one heading and drops Fakturovatelné from the tree', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();

        preg_match('#<thead.*?</thead>#s', $html, $head);
        preg_match_all('#<th\b[^>]*>(.*?)</th>#s', $head[0] ?? '', $cells);
        $titles = array_map(static fn (string $cell): string => trim(strip_tags($cell)), $cells[1]);

        expect(timeReportText($html))->toContain('Název Hodiny Částka Částka v Kč Odpracováno Nevyfakturováno Vyfakturováno Nefakturovatelné')
            ->and($html)->toContain('scope="colgroup"')
            ->and($titles)->not->toContain('Fakturovatelné')
            ->and($titles)->toHaveCount(8);
    });

    it('clamps a very long task title to two lines and keeps the whole label as a tooltip', function (): void {
        $client = timeReportClient('Example Client Alpha');
        $project = timeReportProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
        $title = trim(str_repeat('Prepare the quarterly overview for the example board ', 4));
        $task = timeReportTask($this->admin, $project, $title);
        timeReportRate('2026-09-15', 'EUR', '25.000');
        timeReportEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:00:00');

        $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent();

        $row = timeReportRowHtml($html, $task->reference);

        expect(strlen($title))->toBeGreaterThan(190)
            ->and($row)->toContain('line-clamp: 2')
            ->and($row)->toContain('title="'.$task->reference.' · '.$title.'"');
    });

    it('shows markup in a title escaped inside the tooltip and never as a live tag', function (): void {
        $client = timeReportClient('Example Client Alpha');
        $project = timeReportProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
        $tag = '<'.'b'.'>'.'Canary'.'<'.'/b'.'>';
        $quote = '"'.' on'.'mouseover='.'"'.'x';
        $task = timeReportTask($this->admin, $project, 'Review '.$tag.' '.$quote);
        timeReportRate('2026-09-15', 'EUR', '25.000');
        timeReportEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:00:00');

        $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent();

        $row = timeReportRowHtml($html, $task->reference);

        expect($row)->toContain('title="'.e($task->reference.' · Review '.$tag.' '.$quote).'"')
            ->and($row)->not->toContain($tag)
            ->and($row)->not->toContain(' on'.'mouseover='.'"');
    });
});

/**
 * The tags of the tree rows (the `<tr>` with a `data-level`) of a rendered report, with their level, in order.
 *
 * @return list<array{level: string, tag: string, hidden: bool}>
 */
function timeReportLevelRows(string $html): array
{
    preg_match_all('/<tr\b[^>]*\sdata-level="([a-z]+)"[^>]*>/', $html, $matches, PREG_SET_ORDER);

    return array_map(static fn (array $match): array => [
        'level' => $match[1],
        'tag' => $match[0],
        'hidden' => preg_match('/\shidden(?=[\s>])/', $match[0]) === 1,
    ], $matches);
}

describe('the tree opens as deep as the filters', function (): void {
    it('shows one collapsed line per client when no client is chosen', function (): void {
        timeReportRate('2026-09-15', 'EUR', '25.000');

        foreach (['Example Client Alpha' => 'AAA', 'Example Client Beta' => 'BBB'] as $name => $key) {
            $client = timeReportClient($name);
            $project = timeReportProject($client, 'Example Project '.$key, $key);
            $task = timeReportTask($this->admin, $project, 'Work item');
            timeReportEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        }

        $html = (string) $this->get('/admin/time-report?period=last_month')->assertOk()->getContent();
        $toggles = timeReportToggles($html);
        $rows = timeReportLevelRows($html);

        expect($toggles)->toHaveCount(4)
            ->and($html)->toContain('data-expand="Rozbalit Example Client Alpha"')
            ->and($html)->toContain('data-expand="Rozbalit Example Client Beta"')
            ->and(substr_count(implode('', $toggles), 'aria-expanded="true"'))->toBe(0)
            ->and(preg_match('/aria-expanded="false"[^>]*aria-label="Rozbalit Example Client Alpha"/', $html))->toBe(1)
            ->and(preg_match('/aria-expanded="false"[^>]*aria-label="Rozbalit Example Client Beta"/', $html))->toBe(1);

        $clients = array_filter($rows, static fn (array $row): bool => $row['level'] === 'client');
        $below = array_filter($rows, static fn (array $row): bool => in_array($row['level'], ['project', 'task'], true));

        expect($clients)->toHaveCount(2)
            ->and(array_filter($clients, static fn (array $row): bool => $row['hidden']))->toHaveCount(0)
            ->and($below)->toHaveCount(4)
            ->and(array_filter($below, static fn (array $row): bool => ! $row['hidden']))->toHaveCount(0);
    });

    it('opens the client and the project of a project filter and leaves the day rows hidden', function (): void {
        $set = timeReportSplitArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&project='.$set['project']->id.'&period=last_month&split=day')->assertOk()->getContent();
        $toggles = timeReportToggles($html);
        $rows = timeReportLevelRows($html);

        expect($toggles)->toHaveCount(3)
            ->and(preg_match('/aria-expanded="true"[^>]*aria-label="Sbalit Example Client Alpha"/', $html))->toBe(1)
            ->and(preg_match('/aria-expanded="true"[^>]*aria-label="Sbalit Example Project Web"/', $html))->toBe(1);

        $byLevel = static fn (string $level): array => array_values(array_filter($rows, static fn (array $row): bool => $row['level'] === $level));

        expect($byLevel('client'))->toHaveCount(1)
            ->and($byLevel('project'))->toHaveCount(1)
            ->and($byLevel('task'))->toHaveCount(1)
            ->and($byLevel('leaf'))->toHaveCount(3)
            ->and(array_filter($byLevel('task'), static fn (array $row): bool => $row['hidden']))->toHaveCount(0)
            ->and(array_filter($byLevel('leaf'), static fn (array $row): bool => ! $row['hidden']))->toHaveCount(0);
    });

    it('hides the effective-rate row with its project row on the all-clients page and shows it with a client', function (): void {
        $client = timeReportClient('Example Client Alpha');
        $project = timeReportProject($client, 'Example Project Fixed', 'FIX', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        timeReportRate('2026-09-15', 'EUR', '25.000');
        timeReportEntry($project, '2026-09-15 10:00:00', '2026-09-15 12:00:00');

        $effective = static function (string $html): string {
            preg_match('/<tr\b[^>]*\sdata-effective-rate[^>]*>/', $html, $match);

            return $match[0] ?? '';
        };

        $all = $effective((string) $this->get('/admin/time-report?period=last_month')->assertOk()->getContent());
        $one = $effective((string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent());

        expect($all)->not->toBe('')
            ->and(preg_match('/\shidden(?=[\s>])/', $all))->toBe(1)
            ->and($one)->not->toBe('')
            ->and(preg_match('/\shidden(?=[\s>])/', $one))->toBe(0);
    });

    it('keeps the client filter case: the client open, the project closed, two task rows hidden', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();
        $rows = timeReportLevelRows($html);

        expect(array_filter($rows, static fn (array $row): bool => $row['hidden']))->toHaveCount(2)
            ->and(array_column(array_filter($rows, static fn (array $row): bool => $row['hidden']), 'level'))->toBe(['task', 'task']);
    });
});

/**
 * The opening tag of the tree row that contains a name, with the markup up to its closing tag.
 */
function timeReportRowTagOf(string $html, string $needle): string
{
    preg_match('/<tr\b[^>]*>/', timeReportRowHtml($html, $needle), $tag);

    return $tag[0] ?? '';
}

describe('the levels of the tree', function (): void {
    it('keeps the dark-mode and sticky rules in one static style block with the report prefix', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();

        preg_match_all('#<style>(.*?)</style>#s', $html, $blocks);
        $report = array_values(array_filter($blocks[1], static fn (string $css): bool => str_contains($css, 'kokpit-report-')));

        expect($report)->toHaveCount(1);

        $css = $report[0];
        $selectors = array_values(array_filter(array_map(
            static fn (string $rule): string => trim(strstr($rule, '{', true) ?: ''),
            explode('}', $css),
        )));

        expect($selectors)->not->toBeEmpty();

        foreach ($selectors as $selector) {
            expect($selector)->toMatch('/^(\.dark )?\.kokpit-report-/');
        }

        expect($css)->not->toContain('{{')
            ->and($css)->not->toContain('{!!')
            ->and($css)->toContain('position: sticky')
            ->and($css)->toContain('.dark .kokpit-report-name')
            ->and($css)->toContain('.dark .kokpit-report-client')
            ->and($css)->toContain('.dark .kokpit-report-muted');
    });

    it('marks the client row with the band and the Název cells as sticky', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();

        $clientTag = timeReportRowTagOf($html, 'Example Client Alpha');
        $projectTag = timeReportRowTagOf($html, 'Example Project Web');

        preg_match('#<thead.*?</thead>#s', $html, $head);
        preg_match('#<tfoot.*?</tfoot>#s', $html, $foot);
        preg_match_all('#<th\b[^>]*scope="row"[^>]*>#', $html, $rowHeads);

        expect($clientTag)->toContain('kokpit-report-client')
            ->and($projectTag)->not->toContain('kokpit-report-client')
            ->and($head[0] ?? '')->toContain('kokpit-report-name')
            ->and($foot[0] ?? '')->toContain('kokpit-report-name')
            ->and($rowHeads[0])->not->toBeEmpty();

        foreach ($rowHeads[0] as $tag) {
            expect($tag)->toContain('kokpit-report-name');
        }
    });

    it('sets the weights of the levels: client and project at 600, task at 400', function (): void {
        $set = timeReportArrangement();

        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent();

        expect(timeReportRowTagOf($html, 'Example Client Alpha'))->toContain('font-weight: 600')
            ->and(timeReportRowTagOf($html, 'Example Project Web'))->toContain('font-weight: 600')
            ->and(timeReportRowTagOf($html, 'Landing page'))->toContain('font-weight: 400')
            ->and(timeReportRowHtml($html, 'Landing page'))->toMatch('/<th\b[^>]*font-weight: 400[^>]*>/');
    });

    it('sets the day rows, the placeholders and the task reference in the secondary gray without opacity', function (): void {
        $set = timeReportSplitArrangement();
        $html = (string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month&split=day')->assertOk()->getContent();

        $day = timeReportRowTagOf($html, '15. 9. 2026');
        $task = timeReportRowHtml($html, 'Landing page');

        expect($day)->toContain('kokpit-report-muted')
            ->and($task)->toContain('<span class="kokpit-report-muted">'.$set['task']->reference.'</span>')
            ->and($task)->toContain('title="'.$set['task']->reference.' · Landing page"');

        $client = timeReportClient('Example Client Beta');
        $project = timeReportProject($client, 'Example Project Two', 'TWO');
        $titled = timeReportTask($this->admin, $project, 'Second item');
        timeReportEntry($project, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        timeReportEntry($client, '2026-09-15 10:00:00', '2026-09-15 10:30:00');
        timeReportEntry($titled, '2026-09-15 11:00:00', '2026-09-15 11:30:00');

        $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent();

        expect(timeReportRowHtml($html, 'Bez projektu'))->toMatch('/<span title="Bez projektu"\s+class="kokpit-report-muted"/')
            ->and(timeReportRowHtml($html, 'Bez úkolu'))->toMatch('/<span title="Bez úkolu"\s+class="kokpit-report-muted"/')
            ->and($html)->not->toContain('opacity: 0.7');
    });

    it('prints the effective-rate block on the spacing scale and uses no opacity below 0.8', function (): void {
        $client = timeReportClient('Example Client Alpha');
        $project = timeReportProject($client, 'Example Project Fixed', 'FIX', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        timeReportRate('2026-09-15', 'EUR', '25.000');
        timeReportEntry($project, '2026-09-15 10:00:00', '2026-09-15 12:00:00');

        $html = (string) $this->get('/admin/time-report?client='.$client->id.'&period=last_month')->assertOk()->getContent();

        preg_match('#<tr\b[^>]*data-effective-rate.*?</tr>#s', $html, $block);
        $block = $block[0] ?? '';

        expect($block)->not->toBe('')
            ->and($block)->toContain('padding: 0 1rem 0.5rem calc(1rem + 1rem + 1.5rem)')
            ->and($block)->toContain('gap: 0.5rem 2rem')
            ->and($block)->not->toContain('0.75rem')
            ->and($block)->not->toContain('1.75rem')
            ->and($block)->not->toContain('2.5rem');

        preg_match_all('/opacity:\s*([0-9.]+)/', $html, $opacities);

        foreach ($opacities[1] as $value) {
            expect((float) $value)->toBeGreaterThanOrEqual(0.8);
        }
    });
});
