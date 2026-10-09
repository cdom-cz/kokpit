<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The working list of time entries (TI-05, TI-06): the filters on Prague day boundaries, the
 * totals over the whole filtered set and the overlap badge. Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * A stored entry of the signed-in Admin, from UTC instants.
 *
 * @param  array<string, mixed>  $attributes
 */
function listEntry(string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

/**
 * A client with a project and a task, built through the domain Actions.
 *
 * @return array{client: Client, project: Project, task: Task}
 */
function listWorld(string $key = 'AAA'): array
{
    $client = Client::factory()->create();
    $project = app(CreateProject::class)->handle($client, ['name' => 'Example project '.$key, 'key' => $key, 'billing_type' => 'hourly']);
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example '.$key]);

    return ['client' => $client, 'project' => $project, 'task' => $task];
}

it('filters by the Prague day, including the 25-hour day the clocks go back', function (): void {
    // Prague 2026-10-25 12:00; the clocks go back at 03:00 that night, so the day has 25 hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00', 'UTC'));
    $beforeMidnight = listEntry('2026-10-24 21:30:00', '2026-10-24 21:45:00'); // 23:30 on the 24th
    $afterMidnight = listEntry('2026-10-24 22:30:00', '2026-10-24 22:45:00'); // 00:30 on the 25th
    $lastHour = listEntry('2026-10-25 22:30:00', '2026-10-25 22:45:00'); // 23:30 on the 25th
    $nextDay = listEntry('2026-10-25 23:30:00', '2026-10-25 23:45:00'); // 00:30 on the 26th

    Livewire::test(ListTimeEntries::class)
        ->filterTable('period', ['preset' => 'today'])
        ->assertCanSeeTableRecords([$afterMidnight, $lastHour])
        ->assertCanNotSeeTableRecords([$beforeMidnight, $nextDay])
        ->assertSee('Období: Dnes');
});

it('filters by the ISO week, the month and their predecessors in Prague time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00', 'UTC')); // Sunday
    $sundayBefore = listEntry('2026-10-18 21:30:00', '2026-10-18 21:45:00'); // Sunday 23:30, last week
    $monday = listEntry('2026-10-18 22:30:00', '2026-10-18 22:45:00'); // Monday 00:30, this week
    $nextMonday = listEntry('2026-10-25 23:30:00', '2026-10-25 23:45:00'); // Monday the 26th 00:30
    $septemberEnd = listEntry('2026-09-30 21:30:00', '2026-09-30 21:45:00'); // Sept 30 23:30
    $octoberStart = listEntry('2026-09-30 22:30:00', '2026-09-30 22:45:00'); // Oct 1 00:30
    $octoberEnd = listEntry('2026-10-31 22:30:00', '2026-10-31 22:45:00'); // Oct 31 23:30
    $novemberStart = listEntry('2026-10-31 23:30:00', '2026-10-31 23:45:00'); // Nov 1 00:30

    Livewire::test(ListTimeEntries::class)
        ->filterTable('period', ['preset' => 'this_week'])
        ->assertCanSeeTableRecords([$monday])
        ->assertCanNotSeeTableRecords([$sundayBefore, $nextMonday])
        ->filterTable('period', ['preset' => 'last_week'])
        ->assertCanSeeTableRecords([$sundayBefore])
        ->assertCanNotSeeTableRecords([$monday, $nextMonday])
        ->filterTable('period', ['preset' => 'this_month'])
        ->assertCanSeeTableRecords([$octoberStart, $octoberEnd, $monday])
        ->assertCanNotSeeTableRecords([$septemberEnd, $novemberStart])
        ->filterTable('period', ['preset' => 'last_month'])
        ->assertCanSeeTableRecords([$septemberEnd])
        ->assertCanNotSeeTableRecords([$octoberStart, $octoberEnd, $novemberStart]);
});

it('filters a custom period inclusive of both days and shows an indicator for each bound', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00', 'UTC'));
    $before = listEntry('2026-10-11 21:30:00', '2026-10-11 21:45:00'); // Oct 11 23:30
    $first = listEntry('2026-10-11 22:30:00', '2026-10-11 22:45:00'); // Oct 12 00:30
    $last = listEntry('2026-10-13 21:30:00', '2026-10-13 21:45:00'); // Oct 13 23:30
    $after = listEntry('2026-10-13 22:30:00', '2026-10-13 22:45:00'); // Oct 14 00:30

    Livewire::test(ListTimeEntries::class)
        ->filterTable('period', ['preset' => 'custom', 'from' => '2026-10-12', 'until' => '2026-10-13'])
        ->assertCanSeeTableRecords([$first, $last])
        ->assertCanNotSeeTableRecords([$before, $after])
        ->assertSee('Období od: 12. 10. 2026')
        ->assertSee('Období do: 13. 10. 2026');
});

it('narrows the rows by client, project, task and billing state, all combined with AND', function (): void {
    $a = listWorld('AAA');
    $b = listWorld('BBB');
    $onTask = listEntry('2026-10-12 08:00:00', '2026-10-12 09:00:00', ['client_id' => $a['client']->id, 'project_id' => $a['project']->id, 'task_id' => $a['task']->id]);
    $onProject = listEntry('2026-10-12 10:00:00', '2026-10-12 11:00:00', ['client_id' => $a['client']->id, 'project_id' => $a['project']->id]);
    $other = listEntry('2026-10-12 12:00:00', '2026-10-12 13:00:00', ['client_id' => $b['client']->id, 'project_id' => $b['project']->id, 'task_id' => $b['task']->id]);
    $nonBillable = listEntry('2026-10-12 14:00:00', '2026-10-12 15:00:00', ['client_id' => $a['client']->id, 'billable' => false]);
    $billed = listEntry('2026-10-12 16:00:00', '2026-10-12 17:00:00', ['client_id' => $a['client']->id]);
    app(MarkEntriesBilled::class)->handle($this->admin, [$billed->id]);

    Livewire::test(ListTimeEntries::class)
        ->filterTable('client_id', $a['client']->id)
        ->assertCanSeeTableRecords([$onTask, $onProject, $nonBillable, $billed])
        ->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('project_id', $b['project']->id)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$onTask, $onProject, $nonBillable, $billed])
        ->resetTableFilters()
        ->filterTable('task_id', $a['task']->id)
        ->assertCanSeeTableRecords([$onTask])
        ->assertCanNotSeeTableRecords([$onProject, $other, $nonBillable, $billed])
        ->resetTableFilters()
        ->filterTable('billing', 'non_billable')
        ->assertCanSeeTableRecords([$nonBillable])
        ->assertCanNotSeeTableRecords([$onTask, $onProject, $other, $billed])
        ->resetTableFilters()
        ->filterTable('billing', 'billed')
        ->assertCanSeeTableRecords([$billed])
        ->assertCanNotSeeTableRecords([$onTask, $onProject, $other, $nonBillable])
        ->resetTableFilters()
        ->filterTable('billing', 'unbilled')
        ->assertCanSeeTableRecords([$onTask, $onProject, $other])
        ->assertCanNotSeeTableRecords([$nonBillable, $billed])
        // Two filters at once keep only the rows that satisfy both.
        ->filterTable('client_id', $a['client']->id)
        ->assertCanSeeTableRecords([$onTask, $onProject])
        ->assertCanNotSeeTableRecords([$other, $nonBillable, $billed]);
});

it('totals the whole filtered set, not just the page, and splits it into billable and not', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $all = [];
    $total = 0;
    $billable = 0;

    // 30 entries of 61 * n seconds on separate hours of separate days: two pages of 25 and 5.
    for ($n = 1; $n <= 30; $n++) {
        $start = CarbonImmutable::parse('2026-09-01 06:00:00', 'UTC')->addDays($n - 1);
        $seconds = 61 * $n;
        $isBillable = $n % 3 !== 0;
        $all[] = listEntry($start->format('Y-m-d H:i:s'), $start->addSeconds($seconds)->format('Y-m-d H:i:s'), ['billable' => $isBillable]);
        $total += $seconds;
        $billable += $isBillable ? $seconds : 0;
    }

    // A running entry counts at its elapsed time: ten minutes at the frozen clock.
    listEntry('2026-10-20 11:50:00', '');
    $total += 600;
    $billable += 600;

    $page = Livewire::test(ListTimeEntries::class);
    $pageOne = $page->instance()->getTableRecords();

    expect($total)->toBe(61 * 465 + 600)
        ->and($pageOne)->toHaveCount(25);

    $page
        ->assertTableColumnSummarySet('elapsed_seconds', 'total', $total)
        ->assertTableColumnSummarySet('elapsed_seconds', 'billable', $billable)
        ->assertTableColumnSummarySet('elapsed_seconds', 'non_billable', $total - $billable)
        // The page sum is not the set sum.
        ->assertTableColumnSummaryNotSet('elapsed_seconds', 'total', $total, isCurrentPaginationPageOnly: true)
        // 28965 seconds = 8:02:45, shown once as H:MM; the split labels are in the footer too.
        ->assertSee('Celkem')
        ->assertSee('8:02')
        ->assertSee('fakturovatelné')
        ->assertSee('nefakturovatelné');
});

it('totals only the filtered rows when a filter is active', function (): void {
    $a = listWorld('AAA');
    listEntry('2026-10-12 08:00:00', '2026-10-12 09:00:00', ['client_id' => $a['client']->id]);
    listEntry('2026-10-12 10:00:00', '2026-10-12 10:30:00', ['client_id' => $a['client']->id]);
    listEntry('2026-10-12 12:00:00', '2026-10-12 15:00:00');

    Livewire::test(ListTimeEntries::class)
        ->assertTableColumnSummarySet('elapsed_seconds', 'total', 3600 + 1800 + 10800)
        ->filterTable('client_id', $a['client']->id)
        ->assertTableColumnSummarySet('elapsed_seconds', 'total', 5400)
        ->assertTableColumnSummarySet('elapsed_seconds', 'non_billable', 0);
});

it('flags every overlapping row, names the other entry and flags nothing that only touches', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    $a = listEntry('2026-10-12 08:00:00', '2026-10-12 10:00:00', ['client_id' => $client->id]);
    $b = listEntry('2026-10-12 09:00:00', '2026-10-12 11:00:00', ['client_id' => $client->id]);
    $c = listEntry('2026-10-12 09:30:00', '2026-10-12 09:45:00', ['client_id' => $client->id]);
    $touching = listEntry('2026-10-12 11:00:00', '2026-10-12 12:00:00', ['client_id' => $client->id]);
    $zeroLength = listEntry('2026-10-12 09:15:00', '2026-10-12 09:15:00', ['client_id' => $client->id]);
    $otherUser = listEntry('2026-10-12 08:30:00', '2026-10-12 09:30:00', ['user_id' => User::factory()->create()->id]);

    $page = Livewire::test(ListTimeEntries::class);

    foreach ([$a, $b, $c] as $entry) {
        $page->assertTableColumnStateSet('overlap', 'Překryv', $entry);
    }

    foreach ([$touching, $zeroLength, $otherUser] as $entry) {
        $page->assertTableColumnStateSet('overlap', null, $entry);
    }

    $rows = $page->instance()->getTableRecords()->keyBy('id');

    // The label names the first overlapping entry in start order: the task reference or the client.
    expect($rows[$a->id]->overlap_label)->toBe('Cihla')
        ->and($rows[$touching->id]->overlap_label)->toBeNull();
});

it('names a task entry by its reference in the overlap label', function (): void {
    $world = listWorld('AAA');
    listEntry('2026-10-12 08:00:00', '2026-10-12 10:00:00', ['client_id' => $world['client']->id, 'project_id' => $world['project']->id, 'task_id' => $world['task']->id]);
    $other = listEntry('2026-10-12 09:00:00', '2026-10-12 11:00:00');

    $rows = Livewire::test(ListTimeEntries::class)->instance()->getTableRecords()->keyBy('id');

    expect($rows[$other->id]->overlap_label)->toBe($world['task']->reference.' · '.$world['task']->title);
});

it('runs the same number of queries for 30 rows as for 3', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $world = listWorld('AAA');
    $create = static function (int $from, int $to) use ($world): void {
        for ($n = $from; $n <= $to; $n++) {
            listEntry('2026-10-12 08:00:00', '2026-10-12 18:00:00', ['client_id' => $world['client']->id, 'project_id' => $world['project']->id, 'task_id' => $world['task']->id, 'description' => 'Example '.$n]);
        }
    };
    $count = static function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListTimeEntries::class)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $create(1, 3);
    $count(); // warms the permission cache, which loads once per process
    $small = $count();
    $create(4, 30);
    $large = $count();

    expect($large)->toBe($small);
});
