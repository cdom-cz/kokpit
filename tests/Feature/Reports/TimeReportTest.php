<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\TimeReportPage;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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
            ->toContain('Example Client Alpha 3:15 3:15 0:00 0:00 3:15 '.timeReportMoney(39000, 'EUR').' '.timeReportMoney(967500, 'CZK'))
            ->toContain('Example Project Web 3:15 3:15 0:00 0:00 3:15 '.timeReportMoney(39000, 'EUR').' '.timeReportMoney(967500, 'CZK'))
            ->toContain($set['first']->reference.' · Landing page 2:30 2:30 0:00 0:00 2:30 '.timeReportMoney(30000, 'EUR').' '.timeReportMoney(738000, 'CZK'))
            ->toContain($set['second']->reference.' · Contact form 0:45 0:45 0:00 0:00 0:45 '.timeReportMoney(9000, 'EUR').' '.timeReportMoney(229500, 'CZK'));
    });

    it('shows the summary tiles over the whole filtered set and the footer total', function (): void {
        $set = timeReportArrangement();

        $text = timeReportText((string) $this->get('/admin/time-report?client='.$set['client']->id.'&period=last_month')->assertOk()->getContent());

        expect($text)
            ->toContain('Odpracováno 3:15 Fakturovatelné 3:15 Nefakturovatelné 0:00')
            ->toContain('Nevyfakturováno 3:15 '.timeReportMoney(39000, 'EUR').' v Kč: '.timeReportMoney(967500, 'CZK'))
            ->toContain('Nefakturovatelné 0:00 Bez částky')
            ->toContain('Částky jsou vypočtené z aktuálních sazeb a z kurzu ČNB ke dni záznamu.')
            ->toContain('Celkem 3:15 3:15 0:00 0:00 3:15 '.timeReportMoney(39000, 'EUR').' '.timeReportMoney(967500, 'CZK'));
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
