<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectTasksTimeRelationManager;
use App\Filament\Resources\ProjectResource\Widgets\ProjectTimeStats;
use App\Filament\Resources\TaskResource;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin project time overview (PR-05): the stats row, the task tab and the entry tab, and
 * their absence from every Partner surface. Every name is fictional; no money is shown.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * A project of a new client, written through the domain Action; the estimate is in hours.
 *
 * @param  array<string, mixed>  $overrides
 */
function overviewProject(?string $estimateHours = null, array $overrides = []): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example overview '.mb_strtolower(Canary::projectKey()),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'estimate_hours' => $estimateHours,
        ...$overrides,
    ]);
}

/**
 * A stored entry of the signed-in Admin on the project, from UTC instants; an empty end is a
 * running entry.
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewEntry(Project $project, string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->forProject($project)->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

/**
 * Billed time as the Admin would have it: a finished billable entry in the state "billed".
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewBilled(Project $project, string $start, string $end, array $attributes = []): TimeEntry
{
    return overviewEntry($project, $start, $end, [
        'billable' => true,
        'billing_state' => 'billed',
        'billed_at' => CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC'),
        ...$attributes,
    ]);
}

/**
 * The visible words of a rendered page: tags, comments and extra white space removed.
 */
function overviewText(string $html): string
{
    $html = preg_replace(['/<!--.*?-->/s', '/<(script|style)\b.*?<\/\1>/s'], '', $html) ?? $html;
    $html = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($html)) ?? $html);
}

/**
 * Whether the cell that shows exactly `$value` is rendered in danger text. The class name also
 * occurs in the column manager of the table, so the check is anchored on the value.
 */
function overviewDanger(string $html, string $value): bool
{
    return preg_match('/fi-color-danger[^>]*>\s*'.preg_quote($value, '/').'\s*</', $html) === 1;
}

/**
 * The stats row of the project as visible text.
 */
function overviewStats(Project $project): string
{
    return overviewText(Livewire::test(ProjectTimeStats::class, ['record' => $project])->assertSuccessful()->html());
}

/**
 * A task of the project written through the domain Actions; `$billing` may hold `estimate_hours`.
 *
 * @param  array<string, mixed>  $billing
 */
function overviewTask(Project $project, string $title, array $billing = [], ?Task $parent = null): Task
{
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => $title], $parent);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle(test()->admin, $task, $billing);
    }

    return $task->refresh();
}

/**
 * Time on the task, from UTC instants.
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewTaskEntry(Task $task, string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->forTask($task)->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

/**
 * The "Úkoly a čas" tab of the project as a Livewire test.
 */
function overviewTasksTab(Project $project): Testable
{
    return Livewire::test(ProjectTasksTimeRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class]);
}

describe('the stats row', function (): void {
    it('shows the estimate, the worked, billed and unbilled time with the running entry at its elapsed time', function (): void {
        $project = overviewProject('10');

        overviewBilled($project, '2026-10-12 08:00:00', '2026-10-12 10:00:00'); // 2:00 billed
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:30:00'); // 1:30 billable, unbilled
        overviewEntry($project, '2026-10-12 13:00:00', '2026-10-12 13:30:00', ['billable' => false]); // 0:30
        overviewEntry($project, '2026-10-14 09:45:00', ''); // running for 15 minutes

        $text = overviewStats($project);

        expect($text)->toContain('Odhad 10:00')
            ->and($text)->toContain('Odpracováno 4:15 42 % odhadu')
            ->and($text)->toContain('Vyfakturováno 2:00')
            ->and($text)->toContain('Nevyfakturováno 1:45 Nefakturovatelné: 0:30');
    });

    it('keeps the identity worked = billed + unbilled + non-billable in exact seconds', function (): void {
        $project = overviewProject('10');
        overviewBilled($project, '2026-10-12 08:00:00', '2026-10-12 08:59:59');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 11:30:01');
        overviewEntry($project, '2026-10-12 13:00:00', '2026-10-12 13:00:07', ['billable' => false]);
        overviewEntry($project, '2026-10-14 09:59:00', '');

        $totals = app(TimeTotals::class)->forProject($project, CarbonImmutable::now());

        expect($totals['worked'])->toBe(3599 + 1801 + 7 + 60)
            ->and($totals['billed'] + $totals['unbilled'] + $totals['non_billable'])->toBe($totals['worked'])
            ->and($totals['billed'])->toBe(3599)
            ->and($totals['unbilled'])->toBe(1801 + 60)
            ->and($totals['non_billable'])->toBe(7)
            ->and($totals['estimate'])->toBe(36000);
    });

    it('counts the time of the project only, not that of another project', function (): void {
        $project = overviewProject('10');
        $other = overviewProject('10');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:00:00');
        overviewEntry($other, '2026-10-12 11:00:00', '2026-10-12 12:30:00');

        expect(overviewStats($project))->toContain('Odpracováno 1:00')
            ->and(overviewStats($other))->toContain('Odpracováno 1:30');
    });

    it('says how far over the estimate the project is, in danger text', function (): void {
        $project = overviewProject('1');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:45:00');

        $html = Livewire::test(ProjectTimeStats::class, ['record' => $project])->html();

        expect(overviewText($html))->toContain('Odpracováno 1:45 Překročeno o 0:45')
            ->and(preg_match('/fi-color-danger[^>]*>(\s|<[^>]+>)*Překročeno o 0:45/u', $html))->toBe(1);
    });

    it('treats an estimate of 0 as a value: any time exceeds it and there is no percent', function (): void {
        $project = overviewProject('0');

        expect(overviewStats($project))->toContain('Odhad 0:00')
            ->not->toContain('Odhad není zadaný')
            ->not->toContain('% odhadu')
            ->not->toContain('Překročeno');

        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 11:20:00');

        expect(overviewStats($project))->toContain('Překročeno o 0:20')
            ->not->toContain('% odhadu');
    });

    it('shows a dash and "Odhad není zadaný" without an estimate, and zero time on an empty project', function (): void {
        $text = overviewStats(overviewProject());

        expect($text)->toContain('Odhad — Odhad není zadaný')
            ->and($text)->toContain('Odpracováno 0:00')
            ->and($text)->toContain('Vyfakturováno 0:00')
            ->and($text)->toContain('Nevyfakturováno 0:00 Nefakturovatelné: 0:00')
            ->not->toContain('% odhadu');
    });

    it('rounds the percent down, never up', function (): void {
        $project = overviewProject('1');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 11:59:59'); // 99.97 %

        expect(overviewStats($project))->toContain('99 % odhadu');
    });

    it('is on the Admin project page and shows no money', function (): void {
        $project = overviewProject('2');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:00:00');

        $html = $this->get(ProjectResource::getUrl('view', ['record' => $project]))->assertOk()->getContent();
        $text = overviewText((string) $html);

        expect($text)->toContain('Odhad 2:00')
            ->and($text)->toContain('Odpracováno 1:00 50 % odhadu')
            ->and($text)->not->toMatch('/\d\s*(Kč|CZK|EUR|€)/u');
    });

    it('does not tick: the widget has no polling', function (): void {
        $project = overviewProject('2');

        expect(Livewire::test(ProjectTimeStats::class, ['record' => $project])->html())->not->toContain('wire:poll');
    });
});

describe('the registration', function (): void {
    it('registers the widget on the resource and the page, and keeps the panel widget list empty', function (): void {
        expect(ProjectResource::getWidgets())->toBe([ProjectTimeStats::class])
            ->and(Filament::getPanel('admin')->getWidgets())->toBe([]);

        $project = overviewProject();
        $page = Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])->assertSuccessful();

        expect(overviewText($page->html()))->toContain('Odhad není zadaný');
    });

    it('refuses the widget to a Partner, also when forged', function (): void {
        [$clientA] = Canary::twoClients();
        $project = app(CreateProject::class)->handle(Client::query()->findOrFail($clientA), [
            'name' => 'Example partner '.mb_strtolower(Canary::projectKey()),
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'estimate_hours' => '5',
            'client_visible' => true,
        ]);

        $this->actingAs(Canary::partnerFor($clientA));

        expect(ProjectTimeStats::canView())->toBeFalse();
        Livewire::test(ProjectTimeStats::class, ['record' => $project])->assertForbidden();
    });

    it('shows a Partner none of the overview on the own project page', function (): void {
        [$clientA] = Canary::twoClients();
        $project = app(CreateProject::class)->handle(Client::query()->findOrFail($clientA), [
            'name' => 'Example partner '.mb_strtolower(Canary::projectKey()),
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'estimate_hours' => '5',
            'client_visible' => true,
        ]);
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:00:00');

        $html = $this->actingAs(Canary::partnerFor($clientA))
            ->get('/admin/my-projects/'.$project->getRouteKey())
            ->assertOk()
            ->getContent();

        foreach (['Odpracováno', 'Vyfakturováno', 'Nevyfakturováno', 'Odhad', 'Úkoly a čas', 'Časové záznamy'] as $word) {
            expect((string) $html)->not->toContain($word);
        }
    });
});

describe('the tab "Úkoly a čas"', function (): void {
    it('compares the time of a task with its own estimate and shows what is over in danger text', function (): void {
        $project = overviewProject('10');
        $task = overviewTask($project, 'Example estimated task', ['estimate_hours' => '2']);
        overviewTaskEntry($task, '2026-10-12 08:00:00', '2026-10-12 10:30:00');

        $tab = overviewTasksTab($project)->assertSuccessful()
            ->assertTableColumnStateSet('estimate_seconds', '2:00', $task)
            ->assertTableColumnStateSet('worked_seconds', '2:30', $task)
            ->assertTableColumnStateSet('remaining', '-0:30', $task);

        expect(overviewDanger($tab->html(), '-0:30'))->toBeTrue()
            ->and(overviewText($tab->html()))->toContain($task->reference.' Example estimated task');
    });

    it('shows a positive remainder without the danger colour', function (): void {
        $project = overviewProject();
        $task = overviewTask($project, 'Example roomy task', ['estimate_hours' => '3']);
        overviewTaskEntry($task, '2026-10-12 08:00:00', '2026-10-12 09:15:00');

        $tab = overviewTasksTab($project)->assertTableColumnStateSet('remaining', '1:45', $task);

        expect($tab->html())->toContain('>1:45<')
            ->and(overviewDanger($tab->html(), '1:45'))->toBeFalse();
    });

    it('takes the estimate of the parent for a subtask without one, and none from the project', function (): void {
        $project = overviewProject('10');
        $parent = overviewTask($project, 'Example parent', ['estimate_hours' => '2']);
        $subtask = overviewTask($project, 'Example subtask', [], $parent);
        $bare = overviewTask($project, 'Example bare task');
        overviewTaskEntry($bare, '2026-10-12 08:00:00', '2026-10-12 09:00:00');

        overviewTasksTab($project)
            ->assertTableColumnStateSet('estimate_seconds', '2:00', $subtask)
            ->assertTableColumnStateSet('remaining', '2:00', $subtask)
            ->assertTableColumnStateSet('estimate_seconds', '—', $bare)
            ->assertTableColumnStateSet('remaining', null, $bare)
            ->assertTableColumnStateSet('worked_seconds', '1:00', $bare);
    });

    it('treats an own estimate of 0 as a value, also when the parent has one', function (): void {
        $project = overviewProject();
        $parent = overviewTask($project, 'Example parent', ['estimate_hours' => '2']);
        $subtask = overviewTask($project, 'Example zero subtask', ['estimate_hours' => '0'], $parent);
        overviewTaskEntry($subtask, '2026-10-12 08:00:00', '2026-10-12 08:10:00');

        overviewTasksTab($project)
            ->assertTableColumnStateSet('estimate_seconds', '0:00', $subtask)
            ->assertTableColumnStateSet('remaining', '-0:10', $subtask);
    });

    it('shows billed, unbilled and non-billable time of each task, the running entry as unbilled', function (): void {
        $project = overviewProject();
        $task = overviewTask($project, 'Example mixed task');
        overviewTaskEntry($task, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['billing_state' => 'billed', 'billed_at' => CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC')]);
        overviewTaskEntry($task, '2026-10-12 10:00:00', '2026-10-12 10:30:00');
        overviewTaskEntry($task, '2026-10-12 11:00:00', '2026-10-12 11:20:00', ['billable' => false]);
        overviewTaskEntry($task, '2026-10-14 09:50:00', '');

        overviewTasksTab($project)
            ->assertTableColumnStateSet('worked_seconds', '2:00', $task)
            ->assertTableColumnStateSet('billed_seconds', '1:00', $task)
            ->assertTableColumnStateSet('unbilled_seconds', '0:40', $task)
            ->assertTableColumnStateSet('non_billable_seconds', '0:20', $task)
            ->assertTableColumnExists('non_billable_seconds')
            ->assertTableColumnVisible('non_billable_seconds');
    });

    it('lists archived tasks with their time, ordered by the worked time descending', function (): void {
        $project = overviewProject();
        $small = overviewTask($project, 'Example small task');
        $big = overviewTask($project, 'Example big task');
        $archived = overviewTask($project, 'Example archived task');
        overviewTaskEntry($small, '2026-10-12 08:00:00', '2026-10-12 08:10:00');
        overviewTaskEntry($big, '2026-10-12 09:00:00', '2026-10-12 11:00:00');
        overviewTaskEntry($archived, '2026-10-12 12:00:00', '2026-10-12 12:50:00');
        app(ArchiveTask::class)->handle($this->admin, $archived);

        overviewTasksTab($project)
            ->assertCanSeeTableRecords([$big, $archived, $small], inOrder: true)
            ->assertTableColumnStateSet('worked_seconds', '0:50', $archived);
    });

    it('sorts by the time columns, which exist only as sub-selects', function (): void {
        $project = overviewProject();
        $small = overviewTask($project, 'Example small task');
        $big = overviewTask($project, 'Example big task');
        overviewTaskEntry($small, '2026-10-12 08:00:00', '2026-10-12 08:10:00');
        overviewTaskEntry($big, '2026-10-12 09:00:00', '2026-10-12 11:00:00', ['billable' => false]);

        foreach (['worked_seconds', 'billed_seconds', 'unbilled_seconds', 'non_billable_seconds', 'estimate_seconds'] as $column) {
            overviewTasksTab($project)->sortTable($column)->assertSuccessful()->sortTable($column, 'desc')->assertSuccessful();
        }

        overviewTasksTab($project)->sortTable('worked_seconds')->assertCanSeeTableRecords([$small, $big], inOrder: true);
    });

    it('keeps the order when the tasks have no time: by id', function (): void {
        $project = overviewProject();
        $first = overviewTask($project, 'Example first');
        $second = overviewTask($project, 'Example second');

        overviewTasksTab($project)->assertCanSeeTableRecords([$first, $second], inOrder: true);
    });

    it('shows the time without a task on the "Bez úkolu" line and sums everything in "Celkem"', function (): void {
        $project = overviewProject('10');
        $task = overviewTask($project, 'Example counted task', ['estimate_hours' => '4']);
        overviewTaskEntry($task, '2026-10-12 08:00:00', '2026-10-12 09:30:00');
        overviewEntry($project, '2026-10-12 11:00:00', '2026-10-12 12:00:00'); // 1:00 without a task
        overviewEntry($project, '2026-10-12 13:00:00', '2026-10-12 13:10:00', ['billable' => false]);

        $text = overviewText(overviewTasksTab($project)->html());

        // Odhad, Odpracováno, Zbývá (blank), Vyfakturováno, Nevyfakturováno.
        expect($text)->toContain('Bez úkolu — 1:10 0:00 1:00')
            ->and($text)->toContain('Celkem 4:00 2:40 0:00 2:30');

        expect(overviewStats($project))->toContain('Odpracováno 2:40');
    });

    it('sums the estimates of the tasks that hold one, a subtask that only inherits adds none', function (): void {
        $project = overviewProject();
        $parent = overviewTask($project, 'Example parent', ['estimate_hours' => '2']);
        overviewTask($project, 'Example inheriting subtask', [], $parent);
        overviewTask($project, 'Example own subtask', ['estimate_hours' => '1'], $parent);
        overviewTaskEntry($parent, '2026-10-12 08:00:00', '2026-10-12 08:30:00');

        expect(overviewText(overviewTasksTab($project)->html()))->toContain('Celkem 3:00 0:30');
    });

    it('opens the task page from the row and links the number', function (): void {
        $project = overviewProject();
        $task = overviewTask($project, 'Example linked task');
        $url = TaskResource::getUrl('view', ['record' => $task]);

        $html = overviewTasksTab($project)->html();

        expect($html)->toContain($url);
    });

    it('wraps a single unbroken word in the title instead of overflowing', function (): void {
        $project = overviewProject();
        overviewTask($project, str_repeat('Unbroken', 12));

        expect(overviewTasksTab($project)->html())->toContain('overflow-wrap: anywhere');
    });

    it('says so, with its body, when the project has no tasks', function (): void {
        $text = overviewText(overviewTasksTab(overviewProject())->html());

        expect($text)->toContain('V projektu zatím nejsou žádné úkoly')
            ->and($text)->toContain('Čas se tu objeví, jakmile k projektu nebo k jeho úkolům někdo zapíše záznam.');
    });

    it('takes the time of the tasks of this project only', function (): void {
        $project = overviewProject();
        $other = overviewProject();
        $mine = overviewTask($project, 'Example own task');
        $theirs = overviewTask($other, 'Example foreign task');
        overviewTaskEntry($mine, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
        overviewTaskEntry($theirs, '2026-10-12 08:00:00', '2026-10-12 09:30:00');

        overviewTasksTab($project)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertTableColumnStateSet('worked_seconds', '1:00', $mine);
    });

    it('issues the same number of queries for 3 tasks as for 20', function (): void {
        $project = overviewProject('10');
        $count = static function (Project $project): int {
            overviewTasksTab($project)->assertSuccessful(); // warm-up
            DB::flushQueryLog();
            DB::enableQueryLog();
            overviewTasksTab($project)->assertSuccessful();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $fill = static function (Project $project, int $from, int $to): void {
            for ($i = $from; $i < $to; $i++) {
                $task = overviewTask($project, 'Example task '.$i, $i % 2 === 0 ? ['estimate_hours' => '1'] : []);
                overviewTaskEntry($task, '2026-10-12 08:00:00', '2026-10-12 08:'.str_pad((string) ($i % 50), 2, '0', STR_PAD_LEFT).':00');
            }
        };

        $fill($project, 0, 3);
        $withThree = $count($project);
        $fill($project, 3, 20);
        $withTwenty = $count($project);

        expect($withThree)->toBeGreaterThan(0)->and($withTwenty)->toBe($withThree);
    });

    it('refuses the tab to a Partner and does not offer it on the Partner project page', function (): void {
        [$clientA] = Canary::twoClients();
        $project = app(CreateProject::class)->handle(Client::query()->findOrFail($clientA), [
            'name' => 'Example partner '.mb_strtolower(Canary::projectKey()),
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'client_visible' => true,
        ]);

        $this->actingAs(Canary::partnerFor($clientA));

        expect(ProjectTasksTimeRelationManager::canViewForRecord($project, ViewProject::class))->toBeFalse();
        Livewire::test(ProjectTasksTimeRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])->assertForbidden();
    });
});
