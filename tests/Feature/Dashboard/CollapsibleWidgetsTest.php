<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Dashboard\Actions\SetDashboardWidgetCollapsed;
use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Dashboard\Models\DashboardWidgetPreference;
use App\Domain\Dashboard\Queries\CollapsedWidgets;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\PartnerProjects;
use App\Filament\Widgets\PartnerTasks;
use App\Filament\Widgets\TodayPlan;
use App\Filament\Widgets\UnbilledPerClient;
use App\Filament\Widgets\UpcomingDeadlines;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The collapsible dashboard cards: the state is stored per user, applied in the first render, refused for a Partner
 * on an Admin card, and a failed write goes back and says so. Every name below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    // Wednesday 2026-10-14, 10:00 in Prague (08:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

/**
 * The stored rows of a user, read without the owner scope.
 *
 * @return list<object>
 */
function collapsibleDashRows(User $user, ?DashboardWidget $widget = null): array
{
    $query = DB::table('dashboard_widget_preferences')->where('user_id', $user->id);

    if ($widget !== null) {
        $query->where('widget_key', $widget->value);
    }

    return $query->get()->all();
}

/**
 * A finished entry of a user from UTC instants, in a system run.
 */
function collapsibleDashEntry(User $user, string $start, string $end): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
    ]));
}

describe('the hours card as the Admin', function (): void {
    it('starts expanded without a stored row', function (): void {
        $component = Livewire::actingAs($this->admin)->test(HoursStats::class);

        expect($component->get('collapsed'))->toBeFalse()
            ->and($component->html())->toContain('aria-expanded="true"');
    });

    it('stores a toggle in one row, applies it on the next mount and updates the same row', function (): void {
        Livewire::actingAs($this->admin)->test(HoursStats::class)
            ->call('storeCollapsedState', true)
            ->assertReturned(true);

        $rows = collapsibleDashRows($this->admin);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->widget_key)->toBe('hours_stats')
            ->and($rows[0]->collapsed)->toBeTrue();

        $fresh = Livewire::actingAs($this->admin)->test(HoursStats::class);

        expect($fresh->get('collapsed'))->toBeTrue()
            ->and($fresh->html())->toContain('aria-expanded="false"')
            ->and($fresh->html())->toContain('x-cloak');

        $fresh->call('storeCollapsedState', false)->assertReturned(false);

        $rows = collapsibleDashRows($this->admin);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->collapsed)->toBeFalse();
    });

    it('does not change the card of a second Admin', function (): void {
        Livewire::actingAs($this->admin)->test(HoursStats::class)->call('storeCollapsedState', true);

        $second = Canary::admin();

        expect(Livewire::actingAs($second)->test(HoursStats::class)->get('collapsed'))->toBeFalse()
            ->and(collapsibleDashRows($second))->toBe([]);
    });

    it('refuses a client write to the collapsed property', function (): void {
        $component = Livewire::actingAs($this->admin)->test(HoursStats::class);

        expect(fn () => $component->set('collapsed', true))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(collapsibleDashRows($this->admin))->toBe([]);
    });

    it('shows the heading and the summary of today, and keeps the poll', function (): void {
        collapsibleDashEntry($this->admin, '2026-10-14 06:00:00', '2026-10-14 07:00:00');

        $html = Livewire::actingAs($this->admin)->test(HoursStats::class)->html();

        expect($html)->toContain('Odpracovaný čas')
            ->and($html)->toContain('Dnes 1:00')
            ->and($html)->toContain('wire:poll.visible.60s');
    });
});

describe('a Partner and the hours card', function (): void {
    it('is refused by the Action and the Query, and nothing is written', function (): void {
        [$clientId] = Canary::twoClients();
        $partner = Canary::partnerFor($clientId);

        expect(fn () => app(SetDashboardWidgetCollapsed::class)->handle($partner, DashboardWidget::HoursStats, true))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(CollapsedWidgets::class)->isCollapsed($partner, DashboardWidget::HoursStats))
            ->toThrow(AuthorizationException::class)
            ->and(collapsibleDashRows($partner))->toBe([])
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => DashboardWidgetPreference::query()->count()))->toBe(0);
    });

    it('is forbidden on a forged Livewire mount', function (): void {
        [$clientId] = Canary::twoClients();

        Livewire::actingAs(Canary::partnerFor($clientId))->test(HoursStats::class)->assertForbidden();
    });
});

describe('a failed write', function (): void {
    it('goes back to the previous state, says so in a toast and stores nothing', function (): void {
        app()->bind(SetDashboardWidgetCollapsed::class, fn (): SetDashboardWidgetCollapsed => new class extends SetDashboardWidgetCollapsed
        {
            public function handle(User $actor, DashboardWidget $widget, bool $collapsed): void
            {
                throw new QueryException('pgsql', 'insert into example', [], new RuntimeException('example failure'));
            }
        });

        Livewire::actingAs($this->admin)->test(HoursStats::class)
            ->call('storeCollapsedState', true)
            ->assertReturned(false)
            ->assertNotified(__('kokpit.dashboard.collapse.store_failed'))
            ->assertSet('collapsed', false);

        expect(collapsibleDashRows($this->admin))->toBe([]);
    });
});

/**
 * A task of the planner of a user for today, in the frozen week.
 */
function collapsibleDashPlanTask(User $user, string $title, bool $done = false): SignalTask
{
    $task = new SignalTask;
    $task->forceFill([
        'user_id' => $user->id,
        'title' => $title,
        'for_date' => '2026-10-14',
        'category' => SignalCategory::Other,
        'position' => 0,
        'is_done' => $done,
        'completed_at' => $done ? now() : null,
    ])->save();

    return $task;
}

/**
 * A fictional client with a project, in a system run.
 *
 * @return array{0: Client, 1: Project}
 */
function collapsibleDashClientProject(string $name): array
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($name): array {
        $client = Client::factory()->create([
            'name' => $name,
            'currency' => 'EUR',
            'hourly_rate' => Money::fromMajor('80', 'EUR'),
        ]);

        return [$client, Project::factory()->create(['client_id' => $client->id, 'name' => 'Example Project '.$name])];
    });
}

/**
 * The visible text of a rendered widget: icons, comments and scripts removed, tags stripped, white space collapsed.
 */
function collapsibleDashText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

describe('the registry of the dashboard widgets', function (): void {
    it('puts every widget of the admin panel on the collapsible card with its own key', function (): void {
        $widgets = Filament::getPanel('admin')->getWidgets();
        $keys = [];

        foreach ($widgets as $widget) {
            expect(in_array(CollapsibleDashboardWidget::class, class_uses_recursive($widget), true))->toBeTrue($widget.' is not collapsible');

            $key = $widget::dashboardWidget();
            $keys[] = $key;

            expect($key->isPartnerWidget())->toBe(AccessRules::for($widget)?->audience === Audience::PartnerAllowed, $widget);
        }

        expect($widgets)->toHaveCount(count(DashboardWidget::cases()))
            ->and(array_map(static fn (DashboardWidget $key): string => $key->value, $keys))->toEqualCanonicalizing(array_map(static fn (DashboardWidget $key): string => $key->value, DashboardWidget::cases()));
    });
});

describe('the other Admin cards', function (): void {
    it('start expanded, store a toggle and apply it on the next mount', function (string $widget, DashboardWidget $key): void {
        $first = Livewire::actingAs($this->admin)->test($widget);

        expect($first->get('collapsed'))->toBeFalse()
            ->and($first->html())->toContain('aria-expanded="true"');

        $first->call('storeCollapsedState', true)->assertReturned(true);

        $rows = collapsibleDashRows($this->admin, $key);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->collapsed)->toBeTrue();

        $fresh = Livewire::actingAs($this->admin)->test($widget);

        expect($fresh->get('collapsed'))->toBeTrue()
            ->and($fresh->html())->toContain('aria-expanded="false"')
            ->and($fresh->html())->toContain('x-cloak');
    })->with([
        'plan' => [TodayPlan::class, DashboardWidget::TodayPlan],
        'unbilled' => [UnbilledPerClient::class, DashboardWidget::UnbilledPerClient],
        'deadlines' => [UpcomingDeadlines::class, DashboardWidget::UpcomingDeadlines],
    ]);

    it('summarises the plan, the unbilled hours and the overdue tasks', function (): void {
        collapsibleDashPlanTask($this->admin, 'Example plan finished', done: true);
        collapsibleDashPlanTask($this->admin, 'Example plan open');

        [$client, $project] = collapsibleDashClientProject('Example Client Summary');

        app(PartnerContext::class)->runAsSystem(function () use ($client): void {
            TimeEntry::factory()->state(['client_id' => $client->id])->create([
                'user_id' => $this->admin->id,
                'started_at' => CarbonImmutable::parse('2026-10-14 05:00:00', 'UTC'),
                'ended_at' => CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'),
            ]);
        });

        $deadlines = Livewire::actingAs($this->admin)->test(UpcomingDeadlines::class)->html();

        app(PartnerContext::class)->runAsSystem(function () use ($project): void {
            Task::factory()->create(['project_id' => $project->id, 'title' => 'Example overdue one', 'due_date' => '2026-10-10']);
            Task::factory()->create(['project_id' => $project->id, 'title' => 'Example overdue two', 'due_date' => '2026-10-12']);
            Task::factory()->create(['project_id' => $project->id, 'title' => 'Example due later', 'due_date' => '2026-10-20']);
        });

        expect(collapsibleDashText(Livewire::actingAs($this->admin)->test(TodayPlan::class)->html()))->toContain('Splněno 1 z 2')
            ->and(collapsibleDashText(Livewire::actingAs($this->admin)->test(UnbilledPerClient::class)->html()))->toContain('3:00 h')
            ->and(collapsibleDashText($deadlines))->toContain('Vše v termínu')
            ->and(collapsibleDashText(Livewire::actingAs($this->admin)->test(UpcomingDeadlines::class)->html()))->toContain('2 úkoly po termínu');
    });

    it('shows the title of a table card once, without the class-name fallback, and keeps the report link', function (): void {
        [$client] = collapsibleDashClientProject('Example Client Heading');

        $unbilled = Livewire::actingAs($this->admin)->test(UnbilledPerClient::class)->html();
        $deadlines = Livewire::actingAs($this->admin)->test(UpcomingDeadlines::class)->html();

        expect(substr_count(collapsibleDashText($deadlines), 'Nejbližší termíny'))->toBe(1)
            ->and(substr_count(collapsibleDashText($unbilled), 'Nevyfakturováno tento měsíc'))->toBe(1)
            ->and($deadlines)->not->toContain('Upcoming Deadlines')
            ->and($unbilled)->not->toContain('Unbilled Per Client')
            ->and($unbilled)->toContain('/admin/time-report?period=this_month')
            ->and(collapsibleDashText($unbilled))->toContain('Zobrazit v reportu');
    });
});

describe('the Partner cards', function (): void {
    beforeEach(function (): void {
        [$this->clientA, $this->clientB] = Canary::twoClients();
        $this->partnerA = Canary::partnerFor($this->clientA);
        $this->partnerB = Canary::partnerFor($this->clientA);
    });

    it('store the state per Partner, never for the other Partner of the same client or for an Admin', function (string $widget, DashboardWidget $key): void {
        Livewire::actingAs($this->partnerA)->test($widget)->call('storeCollapsedState', true)->assertReturned(true);

        $fresh = Livewire::actingAs($this->partnerA)->test($widget);

        expect($fresh->get('collapsed'))->toBeTrue()
            ->and($fresh->html())->toContain('aria-expanded="false"')
            ->and(Livewire::actingAs($this->partnerB)->test($widget)->get('collapsed'))->toBeFalse()
            ->and(collapsibleDashRows($this->partnerB))->toBe([])
            ->and(collapsibleDashRows($this->admin))->toBe([])
            ->and(collapsibleDashRows($this->partnerA, $key))->toHaveCount(1);
    })->with([
        'tasks' => [PartnerTasks::class, DashboardWidget::PartnerTasks],
        'projects' => [PartnerProjects::class, DashboardWidget::PartnerProjects],
    ]);

    it('let a Partner query only the own preference rows', function (): void {
        $action = app(SetDashboardWidgetCollapsed::class);
        $action->handle($this->partnerA, DashboardWidget::PartnerTasks, true);
        $action->handle($this->partnerA, DashboardWidget::PartnerProjects, true);
        $action->handle($this->partnerB, DashboardWidget::PartnerTasks, true);
        $action->handle($this->admin, DashboardWidget::HoursStats, true);

        $this->actingAs($this->partnerA);

        $own = DashboardWidgetPreference::query()->pluck('user_id')->unique()->values()->all();

        expect($own)->toBe([$this->partnerA->id])
            ->and(DashboardWidgetPreference::query()->count())->toBe(2);
    });

    it('refuse the four Admin keys at the Action and the Query, and store nothing', function (): void {
        $adminKeys = [DashboardWidget::HoursStats, DashboardWidget::TodayPlan, DashboardWidget::UnbilledPerClient, DashboardWidget::UpcomingDeadlines];

        foreach ($adminKeys as $key) {
            expect(fn () => app(SetDashboardWidgetCollapsed::class)->handle($this->partnerA, $key, true))->toThrow(AuthorizationException::class)
                ->and(fn () => app(CollapsedWidgets::class)->isCollapsed($this->partnerA, $key))->toThrow(AuthorizationException::class);
        }

        expect(collapsibleDashRows($this->partnerA))->toBe([]);
    });

    it('refuse a Partner without a client for both Partner keys', function (): void {
        $orphan = Canary::partnerFor(null);

        foreach ([DashboardWidget::PartnerTasks, DashboardWidget::PartnerProjects] as $key) {
            expect(fn () => app(SetDashboardWidgetCollapsed::class)->handle($orphan, $key, true))->toThrow(AuthorizationException::class)
                ->and(fn () => app(CollapsedWidgets::class)->isCollapsed($orphan, $key))->toThrow(AuthorizationException::class);
        }

        expect(collapsibleDashRows($orphan))->toBe([]);
    });

    it('show counts only, as "Otevřeno: N" and "Projektů: N", and no duration', function (): void {
        $project = app(PartnerContext::class)->runAsSystem(fn (): Project => Project::factory()->create(['client_id' => $this->clientA, 'client_visible' => true]));

        app(PartnerContext::class)->runAsSystem(function () use ($project): void {
            Task::factory()->create(['project_id' => $project->id, 'status' => 'planned']);
            Task::factory()->create(['project_id' => $project->id, 'status' => 'in_review']);
            Task::factory()->create(['project_id' => $project->id, 'status' => 'done']);
        });

        $tasks = collapsibleDashText(Livewire::actingAs($this->partnerA)->test(PartnerTasks::class)->html());
        $projects = collapsibleDashText(Livewire::actingAs($this->partnerA)->test(PartnerProjects::class)->html());
        $page = collapsibleDashText((string) $this->actingAs($this->partnerA)->get('/admin')->assertOk()->getContent());

        expect($tasks)->toContain('Otevřeno: 2')
            ->and($projects)->toContain('Projektů: 1')
            ->and($page)->not->toMatch('/\b\d{1,3}:\d{2}\b/')
            ->and(substr_count($projects, 'Moje projekty'))->toBe(1)
            ->and($projects)->not->toContain('Partner Projects');
    });
});
