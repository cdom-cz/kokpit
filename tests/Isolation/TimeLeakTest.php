<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Operations\Alerts\AdminAlerter;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\FixedPriceSummary;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Notifications\ReportExportReadyNotification;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\WorkReport\WorkReportSource;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Jobs\NotifyLongRunningTimers;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Pages\TimeReportPage;
use App\Filament\Pages\TimesheetPage;
use App\Filament\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use App\Filament\Resources\ExchangeRateResource\Widgets\ExchangeRateStats;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectTasksTimeRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectTimeEntriesRelationManager;
use App\Filament\Resources\ProjectResource\Widgets\ProjectTimeStats;
use App\Filament\Resources\ReportExportResource\Pages\ListReportExports;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\TomorrowPlan;
use App\Filament\Widgets\UnbilledPerClient;
use App\Filament\Widgets\UpcomingDeadlines;
use App\Livewire\TimeTracking\RecentEntriesPanel;
use App\Livewire\TimeTracking\TimerBar;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/*
 * Roadmap Phase 8 (exchange rates and reports), threat T-08-69 and the UI-SPEC Access contract: a Partner meets no
 * Phase 8 surface and no time, rate, price, amount, CNB rate or export summary on any page, in the bell or in the
 * domain services. The fixtures of the block sit on top of the two canary clients above: invented CNB rates, a ready
 * export of the Admin whose summary carries a canary of its own, and the two Admin notices of the phase in the Admin's
 * bell (the export-ready notice and the CNB failure alert). The canary task of every client is in review, so the
 * Partner dashboard is rendered with the "Ke kontrole" highlight.
 */

/**
 * The strings of the UI-SPEC list "Never on a Partner surface" that a Partner page must never carry. Matched as plain
 * text anywhere in the HTML, the Livewire snapshots included.
 *
 * @return list<string>
 */
function leakPhase8Forbidden(): array
{
    return [
        'Odpracováno', 'Nevyfakturováno', 'Vyfakturováno', 'Fakturovatelné', 'Nefakturovatelné', 'Částka', 'Kč', 'EUR',
        'sazba', 'Pevná cena', 'Kurz', 'Report času', 'Exporty', 'Plán na zítřek',
    ];
}

/**
 * Asserts that a Partner response body holds none of the Phase 8 strings and none of the fixture values.
 *
 * @param  list<string>  $values  rates, amounts, export summaries and canaries of the fixtures
 */
function leakPhase8AssertClean(string $html, string $where, array $values): void
{
    foreach (leakPhase8Forbidden() as $text) {
        expect(str_contains($html, $text))->toBeFalse("{$where} must not hold \"{$text}\"");
    }

    foreach ($values as $value) {
        expect(str_contains($html, $value))->toBeFalse("{$where} must not hold {$value}");
    }

    foreach (['3:17:41', '3:17', '03:17', '11897'] as $duration) {
        expect(str_contains($html, $duration))->toBeFalse("{$where} must not hold the duration {$duration}");
    }
}

describe('Phase 8: the Partner and the rates, reports and dashboard surfaces', function (): void {
    beforeEach(function (): void {
        $this->exportSummary = Canary::canary('export');
        $this->rateValues = ['25,125', '22,500', '25.1250000000', '22.5000000000'];

        leakSystem(function (): void {
            $now = TimerClock::now();

            foreach ([['EUR', '25.1250000000'], ['USD', '22.5000000000']] as [$currency, $rate]) {
                ExchangeRate::query()->insert([
                    'rate_date' => '2026-10-09',
                    'currency' => $currency,
                    'unit_amount' => 1,
                    'rate' => $rate,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // A ready export of the Admin whose summary carries the canary (the owner scope limits even the Admin).
            (new ReportExport(['format' => ReportExportFormat::Csv, 'summary' => $this->exportSummary, 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-09']]))
                ->forceFill([
                    'user_id' => $this->admin->getKey(),
                    'status' => ReportExportStatus::Ready,
                    'path' => 'report-exports/'.Str::uuid().'.csv',
                    'row_count' => 2,
                    'completed_at' => $now,
                    'expires_at' => $now->addDays(7),
                ])->save();

            // The two Admin notices of the phase: the export-ready notice and the CNB failure alert.
            $this->admin->notifyNow(new ReportExportReadyNotification('CSV', $this->exportSummary, '16. 10. 2026', '/admin/report-exports'));

            app(AdminAlerter::class)->alert('cnb-rates:2026-10-09', new OperationalAlert(
                __('kokpit.rates.alert.title'),
                __('kokpit.rates.alert.body', ['date' => '9. 10. 2026', 'last' => '8. 10. 2026']),
                '/admin/exchange-rates',
                __('kokpit.rates.alert.action'),
            ));
        });

        $this->phase8Values = [...$this->rateValues, $this->exportSummary, $this->entryCanaryA, $this->entryCanaryB, $this->markupWord, $this->canaryB];
    });

    it('proves the fixtures exist: the Admin sees the rates, the export, the report and the notices', function (): void {
        $this->actingAs($this->admin);

        $this->get('/admin/time-report')->assertOk();
        $this->get('/admin/exchange-rates')->assertOk()->assertSee('25,125')->assertSee('22,500');
        $this->get('/admin/report-exports')->assertOk()->assertSee($this->exportSummary);
        $this->get('/admin')->assertOk()->assertDontSee('Zkontrolovat úkol');

        $bell = Livewire::test(DatabaseNotifications::class)->html();

        expect($bell)->toContain(__('kokpit.exports.bell.ready.title'))
            ->and($bell)->toContain(__('kokpit.rates.alert.title'))
            ->and($bell)->toContain($this->exportSummary);
    });

    it('answers 403 to a Partner on the report, the rates overview and the exports list', function (): void {
        $this->actingAs($this->partnerA);

        foreach (['/admin/time-report', '/admin/exchange-rates', '/admin/report-exports'] as $url) {
            $response = $this->get($url);
            $response->assertForbidden();

            leakPhase8AssertClean((string) $response->getContent(), $url, $this->phase8Values);
        }
    });

    it('answers 403 to a Partner who mounts any Phase 8 page, table or widget', function (): void {
        $this->actingAs($this->partnerA);

        foreach ([
            TimeReportPage::class, ListExchangeRates::class, ExchangeRateStats::class, ListReportExports::class,
            HoursStats::class, UnbilledPerClient::class, UpcomingDeadlines::class, TomorrowPlan::class,
        ] as $component) {
            Livewire::test($component)->assertForbidden();
        }
    });

    it('shows the Partner dashboard with the review highlight and none of the Phase 8 strings or the other client', function (): void {
        $this->actingAs($this->partnerA);

        $html = (string) $this->get('/admin')->assertOk()->getContent();

        // The canary task of client A is in review, so the highlight path is rendered: its title, the badge and the button.
        expect($html)->toContain($this->canaryA)
            ->and($html)->toContain('Ke kontrole')
            ->and($html)->toContain('Zkontrolovat úkol');

        leakPhase8AssertClean($html, 'the Partner dashboard', $this->phase8Values);
        leakAssertClean($html, 'the Partner dashboard', [$this->entryCanaryA, $this->entryCanaryB, $this->markupWord, $this->canaryB]);
    });

    it('shows the Partner no Phase 8 notice in the bell while the Admin has both', function (): void {
        $this->actingAs($this->partnerA);

        expect(DB::table('notifications')->where('notifiable_id', $this->partnerA->id)->count())->toBe(0);

        $everything = (string) DB::table('notifications')->where('notifiable_id', $this->partnerA->id)->get()->toJson();
        $bell = Livewire::test(DatabaseNotifications::class)->html();

        foreach ([$everything, $bell] as $where => $text) {
            leakPhase8AssertClean($text, "bell source {$where}", $this->phase8Values);

            expect($text)->not->toContain(__('kokpit.exports.bell.ready.title'))
                ->and($text)->not->toContain(__('kokpit.rates.alert.title'));
        }

        $adminRows = DB::table('notifications')->where('notifiable_id', $this->admin->id)->count();
        expect($adminRows)->toBe(2);
    });

    it('keeps every page a Partner can open free of the Phase 8 strings and of the other client', function (): void {
        $this->actingAs($this->partnerA);

        $pages = [
            'the dashboard' => '/admin',
            'the project list' => '/admin/my-projects',
            'the own project page' => '/admin/my-projects/'.$this->projectA->id,
            'the task list' => '/admin/my-tasks',
            'the own task page' => '/admin/my-tasks/'.$this->taskA->reference,
            'the profile page' => '/admin/profile',
        ];

        foreach ($pages as $where => $url) {
            leakPhase8AssertClean((string) $this->get($url)->assertOk()->getContent(), $where, $this->phase8Values);
        }
    });

    it('refuses the report, work report and fixed price services in a Partner context and lets the converter find no rate', function (): void {
        $now = TimerClock::now();
        $entry = leakSystem(fn (): TimeEntry => TimeEntry::query()->where('description', $this->entryCanaryA)->firstOrFail());

        $this->actingAs($this->partnerA);

        expect(fn () => app(TimeReportReader::class)->entries(ReportFilters::fromInput([], $now), $now))->toThrow(AuthorizationException::class)
            ->and(fn () => app(TimeReportReader::class)->rateCurrencies(ReportFilters::fromInput([], $now)))->toThrow(AuthorizationException::class)
            ->and(fn () => WorkReportSource::forPeriod($this->clientA, '2026-10-01', '2026-10-31', null, $now))->toThrow(AuthorizationException::class)
            ->and(fn () => WorkReportSource::fromEntryIds([$entry->id], $now))->toThrow(AuthorizationException::class)
            ->and(fn () => FixedPriceSummary::for([$this->projectA->id], ReportFilters::fromInput([], $now), $now))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'EUR'), '2026-10-09'))->toThrow(ExchangeRateMissing::class);
    });

    it('lets the same services work in a system run, so the refusals come from the Partner context', function (): void {
        $now = TimerClock::now();
        $entry = leakSystem(fn (): TimeEntry => TimeEntry::query()->where('description', $this->entryCanaryA)->firstOrFail());

        $this->actingAs($this->partnerA);

        leakSystem(function () use ($now, $entry): void {
            expect(app(TimeReportReader::class)->entries(ReportFilters::fromInput([], $now), $now)->count())->toBeGreaterThan(0)
                ->and(WorkReportSource::fromEntryIds([$entry->id], $now)->totalSeconds())->toBe(3 * 3600 + 17 * 60 + 41)
                ->and(FixedPriceSummary::for([$this->projectA->id], ReportFilters::fromInput([], $now), $now))->toBe([])
                ->and(app(ExchangeRateConverter::class)->toCzk(Money::ofMinor(10000, 'EUR'), '2026-10-09')->minor)->toBe(251250);
        });
    });
});
