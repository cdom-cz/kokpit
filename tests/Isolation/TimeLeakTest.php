<?php

declare(strict_types=1);

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Jobs\NotifyLongRunningTimers;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\TimesheetPage;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectTasksTimeRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectTimeEntriesRelationManager;
use App\Filament\Resources\ProjectResource\Widgets\ProjectTimeStats;
use App\Livewire\TimeTracking\RecentEntriesPanel;
use App\Livewire\TimeTracking\TimerBar;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CanaryRegistry;

/*
 * Roadmap Phase 6 success criterion 5, threat T-06-36: no measured time, rate or price reaches
 * a Partner on any surface of the panel, and every time surface refuses them. The two canary
 * clients come from the registry; on top of that each client gets a finished entry with its own
 * description canary (the registry entry carries the project canary, so it cannot tell the
 * entry text from the project name), one finished entry of 3:17:41 on the project of client A,
 * a running entry of the Admin carrying a markup canary, and a forgotten timer of a second
 * Admin that the scheduled job announces in the bell. Every value is fictional and assembled at
 * runtime from fragments.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CanaryRegistry::prepare();

    // Noon on a Friday in Prague, so "today" holds the running and the finished entry of the Admin.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'Europe/Prague'));

    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->canaryA = Canary::canary('client_a');
    $this->canaryB = Canary::canary('client_b');

    CanaryRegistry::seedAll($this->clientA, $this->canaryA);
    CanaryRegistry::seedAll($this->clientB, $this->canaryB);

    $this->projectA = leakSystem(fn (): Project => Project::query()->where('name', $this->canaryA)->firstOrFail());
    $this->projectB = leakSystem(fn (): Project => Project::query()->where('name', $this->canaryB)->firstOrFail());
    $this->taskA = leakSystem(fn (): Task => Task::query()->where('project_id', $this->projectA->id)->firstOrFail());
    $this->taskB = leakSystem(fn (): Task => Task::query()->where('project_id', $this->projectB->id)->firstOrFail());

    $this->admin = Canary::admin();
    $this->entryCanaryA = Canary::canary('entry_a');
    $this->entryCanaryB = Canary::canary('entry_b');

    // A finished entry of 3:17:41 on client A's task and one of one hour on client B's task.
    leakSystem(function (): void {
        $start = CarbonImmutable::parse('2026-10-09 06:00:00', 'Europe/Prague')->utc();

        TimeEntry::factory()->forTask($this->taskA)->create([
            'user_id' => $this->admin->id,
            'description' => $this->entryCanaryA,
            'started_at' => $start,
            'ended_at' => $start->addSeconds(3 * 3600 + 17 * 60 + 41),
        ]);
        TimeEntry::factory()->forTask($this->taskB)->create([
            'user_id' => $this->admin->id,
            'description' => $this->entryCanaryB,
            'started_at' => $start,
            'ended_at' => $start->addHour(),
        ]);
    });

    // The XSS canary (T-06-17, T-06-24): the running entry of the Admin on client A's task. The word between the
    // delimiters is random, so a match cannot be a coincidence.
    $this->markupWord = 'mk'.bin2hex(random_bytes(4));
    $this->markup = implode('', ['<', 'script', '>']).$this->markupWord.implode('', ['<', '/script', '>']);

    leakSystem(function (): void {
        TimeEntry::factory()->forTask($this->taskA)->create([
            'user_id' => $this->admin->id,
            'description' => $this->markup,
            'started_at' => CarbonImmutable::now()->subMinutes(25)->utc(),
            'ended_at' => null,
        ]);
    });

    $this->partnerA = Canary::partnerFor($this->clientA);
});

afterEach(function (): void {
    CanaryRegistry::cleanup();
});

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function leakSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The words that name measured time, billing state or the time surfaces. Matched as plain text
 * anywhere in the HTML, so a hidden element or an attribute would be caught as well.
 *
 * @return list<string>
 */
function leakForbiddenText(): array
{
    return [
        'Časové záznamy', 'Výkaz', 'Odpracováno', 'Nevyfakturováno', 'Vyfakturováno', 'Fakturovatelné',
        'Platná sazba', 'Poslední záznamy', 'Celkem za den', 'Bez úkolu', 'Úkoly a čas', 'Týden',
    ];
}

/**
 * Short words that would also match inside other words, so they are matched as whole words.
 *
 * @return list<string>
 */
function leakForbiddenWords(): array
{
    return ['Den'];
}

/**
 * Asserts that a response body holds nothing of the time features and no value of the fixtures.
 *
 * @param  list<string>  $canaries
 */
function leakAssertClean(string $html, string $where, array $canaries): void
{
    expect(mb_stripos($html, 'časovač'))->toBeFalse("{$where} must hold no timer text");

    foreach (leakForbiddenText() as $text) {
        expect(str_contains($html, $text))->toBeFalse("{$where} must not hold \"{$text}\"");
    }

    foreach (leakForbiddenWords() as $word) {
        expect(preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $html))->toBe(0, "{$where} must not hold the word \"{$word}\"");
    }

    foreach ($canaries as $canary) {
        expect(str_contains($html, $canary))->toBeFalse("{$where} must not hold {$canary}");
    }

    foreach (['3:17:41', '3:17', '03:17', '11897'] as $duration) {
        expect(str_contains($html, $duration))->toBeFalse("{$where} must not hold the duration {$duration}");
    }
}

/**
 * Asserts that the markup canary appears as visible text only.
 */
function leakAssertEscaped(string $html, string $word, string $markup, string $where, bool $mustShow = true): void
{
    expect(str_contains($html, $markup))->toBeFalse("{$where} must not hold the raw markup")
        ->and(str_contains($html, implode('', ['<', 'script', '>']).$word))->toBeFalse("{$where} must not open a script element");

    if ($mustShow) {
        expect(str_contains($html, '&lt;script&gt;'.$word.'&lt;/script&gt;'))->toBeTrue("{$where} must show the markup as escaped text");
    }
}

describe('what a Partner sees', function (): void {
    it('finds no time, rate or price on any page the Partner can open', function (): void {
        $this->actingAs($this->partnerA);

        $pages = [
            'the dashboard' => '/admin',
            'the project list' => '/admin/my-projects',
            'the own project page' => '/admin/my-projects/'.$this->projectA->id,
            'the task list' => '/admin/my-tasks',
            'the own task page' => '/admin/my-tasks/'.$this->taskA->reference,
            'the task creation page' => '/admin/my-tasks/create',
            'the profile page' => '/admin/profile',
        ];

        $canaries = [$this->entryCanaryA, $this->entryCanaryB, $this->markupWord, $this->canaryB];

        foreach ($pages as $where => $url) {
            leakAssertClean((string) $this->get($url)->assertOk()->getContent(), $where, $canaries);
        }
    });

    it('proves the fixtures exist: the Admin sees the time that the Partner does not', function (): void {
        $this->actingAs($this->admin);

        $list = (string) $this->get('/admin/time-entries')->assertOk()->getContent();

        expect($list)->toContain($this->entryCanaryA)
            ->and($list)->toContain('3:17')
            ->and($list)->toContain($this->entryCanaryB);

        $this->get('/admin/timesheet')->assertOk()->assertSee('Výkaz');
        $this->get('/admin/projects/'.$this->projectA->id)->assertOk();
    });

    it('holds no time notice in the bell of a Partner while the Admin who owns the forgotten timer has one', function (): void {
        $forgetful = Canary::admin();
        leakSystem(function () use ($forgetful): void {
            TimeEntry::factory()->forTask($this->taskA)->create([
                'user_id' => $forgetful->id,
                'description' => $this->entryCanaryA,
                'started_at' => CarbonImmutable::now()->subHours(13)->utc(),
                'ended_at' => null,
            ]);
        });

        NotifyLongRunningTimers::dispatchSync();

        $adminRows = DB::table('notifications')->where('notifiable_id', $forgetful->id)->get();
        expect($adminRows)->toHaveCount(1);

        $this->actingAs($this->partnerA);

        expect(DB::table('notifications')->where('notifiable_id', $this->partnerA->id)->count())->toBe(0);

        $everything = (string) DB::table('notifications')->where('notifiable_id', $this->partnerA->id)->get()->toJson();
        $bell = Livewire::test(DatabaseNotifications::class)->html();

        foreach ([$everything, $bell] as $where => $text) {
            expect(mb_stripos($text, 'časovač'))->toBeFalse("bell source {$where} must hold no timer text")
                ->and($text)->not->toContain($this->entryCanaryA)
                ->and($text)->not->toContain('13:00');
        }
    });
});

describe('what a Partner can open', function (): void {
    it('refuses the entry list and the timesheet routes', function (): void {
        $this->actingAs($this->partnerA);

        $entry = leakSystem(fn (): TimeEntry => TimeEntry::query()->where('description', $this->entryCanaryA)->firstOrFail());

        foreach (['/admin/time-entries', '/admin/time-entries/create', '/admin/time-entries/'.$entry->id, '/admin/time-entries/'.$entry->id.'/edit', '/admin/timesheet'] as $url) {
            $response = $this->get($url);
            $response->assertForbidden();

            expect((string) $response->getContent())->not->toContain($this->entryCanaryA);
        }
    });

    it('refuses every time component and relation manager mounted by a Partner', function (): void {
        $this->actingAs($this->partnerA);

        Livewire::test(TimerBar::class)->assertForbidden();
        Livewire::test(RecentEntriesPanel::class)->assertForbidden();
        Livewire::test(TimesheetPage::class)->assertForbidden();
        Livewire::test(ProjectTimeStats::class, ['record' => $this->projectA])->assertForbidden();

        foreach ([ProjectTasksTimeRelationManager::class, ProjectTimeEntriesRelationManager::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $this->projectA, 'pageClass' => ViewProject::class])->assertForbidden();
        }
    });

    it('refuses the components that a Partner mounts for the project of the other client as well', function (): void {
        $this->actingAs($this->partnerA);

        Livewire::test(ProjectTimeStats::class, ['record' => $this->projectB])->assertForbidden();

        foreach ([ProjectTasksTimeRelationManager::class, ProjectTimeEntriesRelationManager::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $this->projectB, 'pageClass' => ViewProject::class])->assertForbidden();
        }
    });
});

describe('what the Admin sees of a description', function (): void {
    it('renders it escaped on the list, the view page, the timesheet, the bar and the panel', function (): void {
        $this->actingAs($this->admin);
        $entry = leakSystem(fn (): TimeEntry => TimeEntry::query()->where('description', $this->markup)->firstOrFail());

        leakAssertEscaped((string) $this->get('/admin/time-entries')->assertOk()->getContent(), $this->markupWord, $this->markup, 'the list');
        leakAssertEscaped((string) $this->get('/admin/time-entries/'.$entry->id)->assertOk()->getContent(), $this->markupWord, $this->markup, 'the view page');
        leakAssertEscaped((string) $this->get('/admin/timesheet')->assertOk()->getContent(), $this->markupWord, $this->markup, 'the timesheet');
        leakAssertEscaped(Livewire::test(RecentEntriesPanel::class)->html(), $this->markupWord, $this->markup, 'the panel');

        // The bar shows the running entry's client and task; it must not turn a description into markup either.
        leakAssertEscaped(Livewire::test(TimerBar::class)->html(), $this->markupWord, $this->markup, 'the bar', mustShow: false);
        leakAssertEscaped((string) $this->get('/admin')->assertOk()->getContent(), $this->markupWord, $this->markup, 'the dashboard with the bar and the panel', mustShow: false);
    });
});
