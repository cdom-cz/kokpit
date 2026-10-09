<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimesheetQuery;
use App\Filament\Pages\TimesheetPage;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The timesheet "Výkaz" (TI-06; D-04, D-05): the day view of the signed-in Admin and the week
 * grid, both on Prague days. Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * A stored entry of the signed-in Admin from UTC instants; an empty end is a running entry.
 *
 * @param  array<string, mixed>  $attributes
 */
function sheetEntry(string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

/**
 * The labels of the navigation items the signed-in user is offered.
 *
 * @return list<string>
 */
function sheetNavigationLabels(): array
{
    $labels = [];

    foreach (Filament::getNavigation() as $group) {
        foreach ($group->getItems() as $item) {
            $labels[] = (string) $item->getLabel();
        }
    }

    return $labels;
}

/**
 * The visible text of a rendered page: tags, icons and comments removed, white space collapsed.
 */
function sheetText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s'], '', $html);

    return trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('>', '> ', $html))));
}

describe('the day view', function (): void {
    it('lists the entries of today by start with the day total and the summary line', function (): void {
        // Wednesday 2026-10-14, 12:00 in Prague (summer time ended on the 25th, so UTC+2 now).
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $morning = sheetEntry('2026-10-14 06:00:00', '2026-10-14 07:30:00'); // 08:00-09:30
        $later = sheetEntry('2026-10-14 08:00:00', '2026-10-14 08:45:00', ['billable' => false]); // 10:00-10:45
        $running = sheetEntry('2026-10-14 09:40:00', ''); // started 20 minutes ago, 11:40 Prague
        $yesterday = sheetEntry('2026-10-13 06:00:00', '2026-10-13 07:00:00');
        $other = TimeEntry::factory()->create([
            'user_id' => User::factory(),
            'started_at' => CarbonImmutable::parse('2026-10-14 05:00:00', 'UTC'),
            'ended_at' => CarbonImmutable::parse('2026-10-14 06:00:00', 'UTC'),
        ]);

        Livewire::test(TimesheetPage::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$morning, $later, $running], inOrder: true)
            ->assertCanNotSeeTableRecords([$yesterday, $other])
            ->assertSee('Celkem za den')
            ->assertSee('Celkem 2:35 · fakturovatelné 1:50 · nefakturovatelné 0:45')
            ->assertSee('08:00–09:30')
            ->assertSee('11:40–')
            ->assertSee('Běží');
    });

    it('shows the day total over the exact seconds, not the sum of rounded rows', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        // Three stretches of 59 seconds: 0:00 each as a row, 2:57 together read as 0:02.
        sheetEntry('2026-10-14 06:00:00', '2026-10-14 06:00:59');
        sheetEntry('2026-10-14 06:10:00', '2026-10-14 06:10:59');
        sheetEntry('2026-10-14 06:20:00', '2026-10-14 06:20:59');

        Livewire::test(TimesheetPage::class)
            ->assertSee('Celkem 0:02 · fakturovatelné 0:02 · nefakturovatelné 0:00');
    });

    it('shows a dash for the project and the task of a client-only entry', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $client = Client::factory()->create(['name' => 'Example Client One']);
        sheetEntry('2026-10-14 06:00:00', '2026-10-14 07:00:00', ['client_id' => $client->id]);

        $this->get('/admin/timesheet')
            ->assertOk()
            ->assertSee('Example Client One')
            ->assertSee('—');
    });

    it('refuses a Partner and gives it no navigation item', function (): void {
        $client = Client::factory()->create();
        $this->actingAs(Canary::partnerFor($client->id));

        $this->get('/admin/timesheet')->assertForbidden();
        $this->get('/admin/timesheet?view=week&date=2026-10-14')->assertForbidden();

        expect(TimesheetPage::canAccess())->toBeFalse()
            ->and(sheetNavigationLabels())->not->toContain('Výkaz');

        $html = (string) $this->get('/admin')->assertOk()->getContent();
        expect($html)->not->toContain('Výkaz');
    });

    it('gives the Admin a navigation item after the entries', function (): void {
        expect(TimesheetPage::canAccess())->toBeTrue()
            ->and(sheetNavigationLabels())->toContain('Výkaz')
            ->and(TimesheetPage::getNavigationSort())->toBe(41)
            ->and(TimesheetPage::getNavigationLabel())->toBe('Výkaz');

        $this->get('/admin/timesheet')->assertOk()->assertSee('Výkaz');
    });
});

/**
 * A client with a project and a task, built through the domain Actions.
 *
 * @return array{client: Client, project: Project, task: Task}
 */
function sheetWorld(string $clientName, string $key): array
{
    $client = Client::factory()->create(['name' => $clientName]);
    $project = app(CreateProject::class)->handle($client, ['name' => 'Example project '.$key, 'key' => $key, 'billing_type' => 'hourly']);
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example task '.$key]);

    return ['client' => $client, 'project' => $project, 'task' => $task];
}

/**
 * The week of Monday 2026-10-12, today being Wednesday the 14th: client Alpha with task AAA-1 on
 * Monday (1:00) and Wednesday (0:30), project AAA without a task on Tuesday (1:30), client Beta
 * without a project on Friday (0:45). Prague is UTC+2 that week.
 *
 * @return array{a: array{client: Client, project: Project, task: Task}, b: Client}
 */
function sheetWeek(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
    $a = sheetWorld('Example Alpha', 'AAA');
    $b = Client::factory()->create(['name' => 'Example Beta']);

    sheetEntry('2026-10-12 06:00:00', '2026-10-12 07:00:00', ['client_id' => $a['client']->id, 'project_id' => $a['project']->id, 'task_id' => $a['task']->id]);
    sheetEntry('2026-10-14 06:00:00', '2026-10-14 06:30:00', ['client_id' => $a['client']->id, 'project_id' => $a['project']->id, 'task_id' => $a['task']->id]);
    sheetEntry('2026-10-13 07:00:00', '2026-10-13 08:30:00', ['client_id' => $a['client']->id, 'project_id' => $a['project']->id]);
    sheetEntry('2026-10-16 10:00:00', '2026-10-16 10:45:00', ['client_id' => $b->id]);

    return ['a' => $a, 'b' => $b];
}

describe('the week grid', function (): void {
    it('groups the week by client, project and task with the totals of the rows, the days and the week', function (): void {
        $w = sheetWeek();

        $grid = app(TimesheetQuery::class)->weekRows($this->admin, '2026-10-14', CarbonImmutable::now());

        expect(array_map(static fn (array $row): array => [$row['client'], $row['project'], $row['task'], $row['total']], $grid['rows']))->toBe([
            ['Example Alpha', 'AAA', $w['a']['task']->reference, 5400],
            ['Example Alpha', 'AAA', null, 5400],
            ['Example Beta', null, null, 2700],
        ])
            ->and($grid['rows'][0]['days'])->toBe([
                '2026-10-12' => 3600, '2026-10-13' => null, '2026-10-14' => 1800, '2026-10-15' => null,
                '2026-10-16' => null, '2026-10-17' => null, '2026-10-18' => null,
            ])
            ->and($grid['day_totals'])->toBe(['2026-10-12' => 3600, '2026-10-13' => 5400, '2026-10-14' => 1800, '2026-10-16' => 2700])
            ->and($grid['total'])->toBe(13500)
            ->and(array_sum(array_column($grid['rows'], 'total')))->toBe($grid['total']);
    });

    it('renders the rows, the empty cells and the footer', function (): void {
        $w = sheetWeek();

        $text = sheetText((string) $this->get('/admin/timesheet?view=week&date=2026-10-14')->assertOk()->getContent());

        expect($text)->toContain('Example Alpha AAA '.$w['a']['task']->reference.' 1:00 — 0:30 — — — — 1:30')
            ->and($text)->toContain('Example Alpha AAA Bez úkolu — 1:30 — — — — — 1:30')
            ->and($text)->toContain('Example Beta Bez projektu Bez úkolu — — — — 0:45 — — 0:45')
            ->and($text)->toContain('Celkem 1:00 1:30 0:30 — 0:45 — — 3:45');
    });

    it('heads the week with its number and dates and the days with links to the day view', function (): void {
        sheetWeek();

        $page = $this->get('/admin/timesheet?view=week&date=2026-10-14')->assertOk();

        $page->assertSee('Týden 42 · 12. 10. – 18. 10.')
            ->assertSeeInOrder(['Po 12. 10.', 'Út 13. 10.', 'St 14. 10.', 'Čt 15. 10.', 'Pá 16. 10.', 'So 17. 10.', 'Ne 18. 10.']);

        $html = (string) $page->getContent();
        expect($html)->toContain(e(TimesheetPage::getUrl(['view' => 'day', 'date' => '2026-10-12'])))
            ->and($html)->toContain(e(TimesheetPage::getUrl(['view' => 'day', 'date' => '2026-10-18'])))
            ->and(substr_count($html, 'aria-current="date"'))->toBe(2); // the header of today and the "Tento týden" button
        expect(preg_match('/<a[^>]*aria-current="date"[^>]*>\s*St 14\. 10\./', $html))->toBe(1);
    });

    it('marks a day whose entries overlap with the warning icon and its tooltip, and no other day', function (): void {
        sheetWeek();
        // Wednesday already holds 08:00-08:30 Prague; this one starts in the middle of it.
        sheetEntry('2026-10-14 06:15:00', '2026-10-14 06:45:00');

        $grid = app(TimesheetQuery::class)->weekRows($this->admin, '2026-10-14', CarbonImmutable::now());
        expect($grid['day_overlaps'])->toBe([
            '2026-10-12' => false, '2026-10-13' => false, '2026-10-14' => true, '2026-10-15' => false,
            '2026-10-16' => false, '2026-10-17' => false, '2026-10-18' => false,
        ]);

        $html = (string) $this->get('/admin/timesheet?view=week&date=2026-10-14')->assertOk()->getContent();
        $tooltip = 'V tento den se záznamy překrývají, součet může být vyšší než skutečný čas.';

        // One warning icon: its tooltip for the pointer and the same words for a screen reader.
        expect(preg_match_all('/x-tooltip="[^"]*se záznamy překrývají/u', $html))->toBe(1)
            ->and(substr_count(sheetText($html), $tooltip))->toBe(1);
    });

    it('moves by seven days and returns to this week', function (): void {
        sheetWeek();

        $html = (string) $this->get('/admin/timesheet?view=week&date=2026-10-14')->assertOk()->getContent();

        expect($html)->toContain(e(TimesheetPage::getUrl(['view' => 'week', 'date' => '2026-10-07'])))
            ->and($html)->toContain(e(TimesheetPage::getUrl(['view' => 'week', 'date' => '2026-10-21'])))
            ->and($html)->toContain(e(TimesheetPage::getUrl(['view' => 'week', 'date' => '2026-10-14'])))
            ->and($html)->toContain('Předchozí týden')
            ->and($html)->toContain('Další týden')
            ->and($html)->toContain('Tento týden');

        // From another week, "Tento týden" links to the week of today.
        $other = (string) $this->get('/admin/timesheet?view=week&date=2026-11-02')->assertOk()->getContent();
        expect($other)->toContain(e(TimesheetPage::getUrl(['view' => 'week', 'date' => '2026-10-14'])))
            ->and(substr_count($other, 'aria-current="date"'))->toBe(0);
    });

    it('moves the day view by one day and keeps the date when the view switches', function (): void {
        sheetWeek();

        $html = (string) $this->get('/admin/timesheet?view=day&date=2026-10-14')->assertOk()->getContent();

        expect($html)->toContain(e(TimesheetPage::getUrl(['view' => 'day', 'date' => '2026-10-13'])))
            ->and($html)->toContain(e(TimesheetPage::getUrl(['view' => 'day', 'date' => '2026-10-15'])))
            ->and($html)->toContain(e(TimesheetPage::getUrl(['view' => 'week', 'date' => '2026-10-14'])))
            ->and($html)->toContain('Předchozí den')
            ->and($html)->toContain('Další den');
    });

    it('sums the week summary line over exact seconds', function (): void {
        sheetWeek();

        $this->get('/admin/timesheet?view=week&date=2026-10-14')
            ->assertOk()
            ->assertSee('Celkem 3:45 · fakturovatelné 3:45 · nefakturovatelné 0:00');
    });

    it('is read only: the grid has no record actions and no row link', function (): void {
        sheetWeek();

        $component = Livewire::withQueryParams(['view' => 'week', 'date' => '2026-10-14'])->test(TimesheetPage::class);

        expect($component->instance()->getTable()->getRecordActions())->toBe([])
            ->and($component->instance()->getTable()->getBulkActions())->toBe([]);
    });
});

/**
 * The seconds of an entry's grid cell and of the day view, read through the page.
 *
 * @return array{day: string, week: string}
 */
function sheetTexts(string $date): array
{
    return [
        'day' => sheetText((string) test()->get('/admin/timesheet?view=day&date='.$date)->assertOk()->getContent()),
        'week' => sheetText((string) test()->get('/admin/timesheet?view=week&date='.$date)->assertOk()->getContent()),
    ];
}

describe('Prague midnight and daylight saving', function (): void {
    it('attributes an entry from 23:30 to 00:30 wholly to the day it started, in both views', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $client = Client::factory()->create(['name' => 'Example Night']);
        // Wednesday 23:30 to Thursday 00:30 Prague (UTC+2).
        sheetEntry('2026-10-14 21:30:00', '2026-10-14 22:30:00', ['client_id' => $client->id]);

        $wednesday = sheetTexts('2026-10-14');
        $thursday = sheetTexts('2026-10-15');

        expect($wednesday['day'])->toContain('23:30–00:30')
            ->and($wednesday['day'])->toContain('Celkem 1:00 · fakturovatelné 1:00 · nefakturovatelné 0:00')
            ->and($wednesday['week'])->toContain('Example Night Bez projektu Bez úkolu — — 1:00 — — — — 1:00')
            ->and($thursday['day'])->not->toContain('23:30–00:30')
            ->and($thursday['day'])->toContain('Celkem 0:00 ·');
    });

    it('puts a Sunday night entry in the week it started and not in the next one', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $client = Client::factory()->create(['name' => 'Example Night']);
        // Sunday 2026-10-18 23:30 to Monday 00:30 Prague.
        sheetEntry('2026-10-18 21:30:00', '2026-10-18 22:30:00', ['client_id' => $client->id]);

        $thisWeek = sheetTexts('2026-10-14')['week'];
        $nextWeek = sheetTexts('2026-10-19')['week'];

        expect($thisWeek)->toContain('Example Night Bez projektu Bez úkolu — — — — — — 1:00 1:00')
            ->and($nextWeek)->not->toContain('Example Night Bez projektu')
            ->and($nextWeek)->toContain('V tomto týdnu nejsou žádné záznamy');
    });

    it('sums the 25-hour day the clocks go back without losing the repeated hour', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00', 'UTC'));
        $client = Client::factory()->create(['name' => 'Example Autumn']);
        $in = ['client_id' => $client->id];
        // 00:30 CEST to 03:30 CET crosses the repeated hour: four hours of work.
        sheetEntry('2026-10-24 22:30:00', '2026-10-25 02:30:00', $in);
        // 23:30 on the 25th to 00:30 on the 26th (UTC+1): the last entry that belongs to the 25th.
        sheetEntry('2026-10-25 22:30:00', '2026-10-25 23:30:00', $in);
        // 00:30 on the 26th starts the next day; 23:30 on the 24th ended the day before.
        sheetEntry('2026-10-25 23:30:00', '2026-10-26 00:00:00', $in);
        sheetEntry('2026-10-24 21:30:00', '2026-10-24 21:45:00', $in);

        $texts = sheetTexts('2026-10-25');

        expect(TimeEntry::query()->orderBy('started_at')->pluck('duration_seconds')->all())->toBe([900, 14400, 3600, 1800])
            ->and($texts['day'])->toContain('Celkem 5:00 · fakturovatelné 5:00 · nefakturovatelné 0:00')
            ->and($texts['day'])->toContain('00:30–03:30')
            ->and($texts['day'])->toContain('23:30–00:30')
            ->and($texts['week'])->toContain('Example Autumn Bez projektu Bez úkolu — — — — — 0:15 5:00 5:15')
            ->and($texts['week'])->toContain('Celkem — — — — — 0:15 5:00 5:15');
    });

    it('sums the 23-hour day the clocks go forward', function (): void {
        $this->travelTo(CarbonImmutable::parse('2027-03-28 10:00:00', 'UTC'));
        $client = Client::factory()->create(['name' => 'Example Spring']);
        $in = ['client_id' => $client->id];
        // 01:30 CET to 03:30 CEST: one hour, the hour from 02:00 does not exist.
        sheetEntry('2027-03-28 00:30:00', '2027-03-28 01:30:00', $in);
        // 23:30 on the 28th (UTC+2) is the last half hour of that day; 00:30 on the 29th is the next day.
        sheetEntry('2027-03-28 21:30:00', '2027-03-28 22:00:00', $in);
        sheetEntry('2027-03-28 22:30:00', '2027-03-28 23:00:00', $in);

        $texts = sheetTexts('2027-03-28');

        expect(TimeEntry::query()->orderBy('started_at')->pluck('duration_seconds')->all())->toBe([3600, 1800, 1800])
            ->and($texts['day'])->toContain('Celkem 1:30 · fakturovatelné 1:30 · nefakturovatelné 0:00')
            ->and($texts['week'])->toContain('Example Spring Bez projektu Bez úkolu — — — — — — 1:30 1:30');
    });
});

describe('the URL', function (): void {
    it('falls back to the day view of today for a bad view or date', function (string $query): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        sheetEntry('2026-10-14 06:00:00', '2026-10-14 07:00:00');

        $html = (string) $this->get('/admin/timesheet?'.$query)->assertOk()->getContent();

        expect($html)->toContain('value="2026-10-14"')
            ->and($html)->toContain('Celkem 1:00 ·')
            ->and($html)->not->toContain('Týden 42');
    })->with([
        'unknown view' => ['view=month'],
        'impossible date' => ['date=2026-13-45'],
        'day that does not exist' => ['date=2026-02-30'],
        'text after the date' => ['date=2026-10-15abc'],
        'not a date' => ['date=yesterday'],
        'array date' => ['date[]=2026-10-15'],
        'array view and date' => ['view[]=week&date[x]=1'],
    ]);

    it('keeps a valid view and date', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

        $this->get('/admin/timesheet?view=week&date=2026-11-03')
            ->assertOk()
            ->assertSee('Týden 45 · 2. 11. – 8. 11.');
        $this->get('/admin/timesheet?view=day&date=2026-11-03')
            ->assertOk()
            ->assertSee('value="2026-11-03"', false);
    });

    it('ignores a forged view or date sent to the page after it was mounted', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        sheetEntry('2026-10-14 06:00:00', '2026-10-14 07:00:00');

        Livewire::test(TimesheetPage::class)
            ->set('mode', 'month; DROP TABLE time_entries')
            ->set('date', "2026-10-14' OR '1'='1")
            ->assertSuccessful()
            ->assertSee('Celkem 1:00 ·');

        expect(TimeEntry::query()->count())->toBe(1);
    });
});

describe('the overlap flag', function (): void {
    it('marks every overlapping row in the day view, also when more than two overlap', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $first = sheetEntry('2026-10-14 06:00:00', '2026-10-14 07:00:00');
        $second = sheetEntry('2026-10-14 06:30:00', '2026-10-14 07:30:00');
        $third = sheetEntry('2026-10-14 06:45:00', '2026-10-14 06:50:00');
        $apart = sheetEntry('2026-10-14 08:00:00', '2026-10-14 08:30:00');

        Livewire::test(TimesheetPage::class)
            ->assertTableColumnStateSet('overlap', 'Překryv', record: $first)
            ->assertTableColumnStateSet('overlap', 'Překryv', record: $second)
            ->assertTableColumnStateSet('overlap', 'Překryv', record: $third)
            ->assertTableColumnStateSet('overlap', null, record: $apart);
    });
});

describe('the cost of a page', function (): void {
    /**
     * The number of queries one request to the page costs, after a warm-up request.
     */
    function sheetQueryCount(string $url): int
    {
        test()->get($url)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Entries on the day of Wednesday 2026-10-14, one more client, project and task each time, so
     * a query per row would show.
     */
    function sheetSpread(int $from, int $to): void
    {
        for ($i = $from; $i < $to; $i++) {
            $w = sheetWorld('Example Client '.$i, 'PX'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26));
            $minutes = $i * 10;
            $day = $i % 5; // spread over Monday to Friday of the week
            $start = CarbonImmutable::parse('2026-10-12 06:00:00', 'UTC')->addDays($day)->addMinutes($minutes);

            sheetEntry($start->toDateTimeString(), $start->addMinutes(5)->toDateTimeString(), [
                'client_id' => $w['client']->id,
                'project_id' => $w['project']->id,
                'task_id' => $w['task']->id,
            ]);
        }
    }

    it('issues the same number of queries for the day and the week with 3 and with 40 entries', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        // Every entry of the day: the same day for the day view, spread over the week for the grid.
        sheetSpread(0, 3);
        $weekWithThree = sheetQueryCount('/admin/timesheet?view=week&date=2026-10-14');
        $dayWithThree = sheetQueryCount('/admin/timesheet?view=day&date=2026-10-14');

        sheetSpread(3, 40);
        $weekWithForty = sheetQueryCount('/admin/timesheet?view=week&date=2026-10-14');
        $dayWithForty = sheetQueryCount('/admin/timesheet?view=day&date=2026-10-14');

        expect(TimeEntry::query()->count())->toBe(40)
            ->and($weekWithForty)->toBe($weekWithThree)
            ->and($dayWithForty)->toBe($dayWithThree);

        // The day view really holds several rows by then (every fifth entry falls on Wednesday).
        expect(sheetText((string) $this->get('/admin/timesheet?view=day&date=2026-10-14')->getContent()))->toContain('Example Client 2 ');
    });
});

describe('empty days and weeks', function (): void {
    it('says so and keeps the controls usable', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

        $day = (string) $this->get('/admin/timesheet?view=day&date=2026-10-20')->assertOk()->getContent();
        $week = (string) $this->get('/admin/timesheet?view=week&date=2026-10-20')->assertOk()->getContent();

        expect(sheetText($day))->toContain('V tento den nejsou žádné záznamy')
            ->and(sheetText($day))->toContain('Zvolte jiný den nebo přidejte záznam v nabídce Časové záznamy.')
            ->and($day)->toContain(e(TimesheetPage::getUrl(['view' => 'day', 'date' => '2026-10-21'])))
            ->and($day)->toContain('name="date"')
            ->and(sheetText($week))->toContain('V tomto týdnu nejsou žádné záznamy')
            ->and(sheetText($week))->toContain('Zvolte jiný týden nebo přidejte záznam v nabídce Časové záznamy.')
            ->and($week)->toContain(e(TimesheetPage::getUrl(['view' => 'week', 'date' => '2026-10-27'])))
            ->and($week)->toContain('name="date"');
    });

    it('scrolls the grid inside its wrapper so that the page never scrolls sideways', function (): void {
        sheetWeek();

        $html = (string) $this->get('/admin/timesheet?view=week&date=2026-10-14')->assertOk()->getContent();

        expect($html)->toContain('min-width: 0; max-width: 100%; overflow-x: auto;');
    });
});
