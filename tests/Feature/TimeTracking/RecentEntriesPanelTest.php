<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\SetTimePanelOpen;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\RecentEntries;
use App\Domain\TimeTracking\TimerRaceLost;
use App\Livewire\TimeTracking\RecentEntriesPanel;
use App\Livewire\TimeTracking\TimerBar;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The side panel "Poslední záznamy" (D-08, UI-SPEC Surface B): the detailed timer on top, the
 * finished entries by Prague day below, and the Admin-only guard on the page and on every Livewire
 * request. Every name is fictional. The clock is frozen at noon Prague time on a Friday.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'Europe/Prague'));
});

/**
 * A finished entry of the Admin that started at the given Prague time and lasted the given minutes.
 *
 * @param  array<string, mixed>  $attributes
 */
function panelEntry(string $startedAt, int $minutes, array $attributes = []): TimeEntry
{
    // Converted to UTC: the model writes the wall-clock value of the instant it is given.
    $start = CarbonImmutable::parse($startedAt, 'Europe/Prague')->utc();

    return TimeEntry::factory()->create(array_merge([
        'user_id' => test()->admin->id,
        'started_at' => $start,
        'ended_at' => $start->addMinutes($minutes),
    ], $attributes));
}

/**
 * A client with a project and a task, created like the screens do.
 *
 * @return array{client: Client, task: Task}
 */
function panelWorld(string $clientName = 'Cihla'): array
{
    $client = Client::factory()->create(['name' => $clientName]);
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Example project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
    ]);
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example task']);

    return ['client' => $client, 'task' => $task];
}

it('renders the panel as a labelled aside on a page of the Admin', function (): void {
    $this->get('/admin')
        ->assertOk()
        ->assertSee('aria-label="Poslední záznamy"', false)
        ->assertSee('<aside', false);
});

it('lists the finished entries of today under "Dnes" with their total and leaves the running one out', function (): void {
    $w = panelWorld();
    $other = Client::factory()->create(['name' => 'Dub']);

    panelEntry('2026-10-09 08:00', 60, ['client_id' => $w['task']->project->client_id, 'project_id' => $w['task']->project_id, 'task_id' => $w['task']->id]);
    panelEntry('2026-10-09 09:30', 30, ['client_id' => $other->id]);
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $other->id, 'description' => 'Example running work', 'started_at' => now()->subMinutes(5)]);

    $component = Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Dnes')
        // 1:00 + 0:30 of the two finished entries; the running one is not counted.
        ->assertSee('1:30')
        ->assertSee($w['task']->reference.' · Example task')
        ->assertSee('Dub');

    $days = $component->instance()->recent['days'];

    expect($days)->toHaveCount(1)
        ->and($days[0]['entries'])->toHaveCount(2)
        ->and($days[0]['total_seconds'])->toBe(5400);

    // The running entry appears in the timer block with its stop button, and not in the list.
    $component->assertSee('Zastavit časovač')->assertSee('Example running work');
});

it('groups yesterday under the Czech weekday and date', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelEntry('2026-10-08 10:00', 90, ['client_id' => $client->id]);

    Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Čtvrtek 8. 10.')
        ->assertSee('1:30')
        ->assertDontSee('Dnes');
});

it('shows a client-only entry with the client name as the title', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelEntry('2026-10-09 08:00', 45, ['client_id' => $client->id, 'description' => 'Example start of the description']);

    $row = Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Cihla')
        ->assertSee('Example start of the description')
        ->instance()->recent['days'][0]['entries'][0];

    expect($row['title'])->toBe('Cihla')
        ->and($row['task_url'])->toBeNull()
        ->and($row['duration_seconds'])->toBe(2700);
});

it('stops the running timer from the panel and lists the entry under "Dnes" afterwards', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'started_at' => now()->subMinutes(20)]);

    $component = Livewire::test(RecentEntriesPanel::class);

    expect($component->instance()->recent['days'])->toBe([]);

    $component->call('stop', $entry->id)
        ->assertDispatched('timer-stopped')
        ->assertNotified('Časovač byl zastaven');

    $days = $component->instance()->recent['days'];

    expect($entry->refresh()->ended_at)->not->toBeNull()
        ->and($days)->toHaveCount(1)
        ->and($days[0]['label'])->toBe('Dnes')
        ->and($days[0]['entries'][0]['id'])->toBe($entry->id)
        ->and($days[0]['total_seconds'])->toBe(1200);
});

it('starts a timer from the panel for the chosen client', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);

    Livewire::test(RecentEntriesPanel::class)
        ->set('clientId', $client->id)
        ->set('description', 'Example panel start')
        ->call('start')
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn');

    $running = TimeEntry::query()->whereNull('ended_at')->sole();

    expect($running->client_id)->toBe($client->id)
        ->and($running->description)->toBe('Example panel start');
});

it('renders nothing of the panel on the pages of a Partner', function (): void {
    $clientId = Canary::twoClients()[0];
    $this->actingAs(Canary::partnerFor($clientId));

    $this->get('/admin')->assertOk()->assertDontSee('Poslední záznamy');
    $this->get('/admin/my-tasks')->assertOk()->assertDontSee('Poslední záznamy');
});

it('refuses a Partner who mounts the panel', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    Livewire::test(RecentEntriesPanel::class)->assertForbidden();
});

it('renders the panel in its default state for a user who never chose', function (): void {
    expect($this->admin->time_panel_open)->toBeNull();

    $this->get('/admin')
        ->assertOk()
        ->assertSee('data-pref="default"', false);

    // Never chosen counts as open for the label; the panel corrects it in the browser below 80rem.
    Livewire::test(TimerBar::class)
        ->assertSeeHtml('aria-label="Skrýt poslední záznamy"')
        ->assertDontSeeHtml('aria-label="Zobrazit poslední záznamy"');
});

it('stores the closed choice and starts the next page closed, without a client round trip', function (): void {
    Livewire::test(RecentEntriesPanel::class)->call('setOpen', false)->assertSet('open', false);

    expect($this->admin->refresh()->time_panel_open)->toBeFalse();

    $this->get('/admin')
        ->assertOk()
        ->assertSee('data-pref="closed"', false);

    // The toggle of the bar is named for the state it will cause; the close button of the panel
    // keeps its own name, so the bar is read on its own.
    Livewire::test(TimerBar::class)
        ->assertSeeHtml('aria-label="Zobrazit poslední záznamy"')
        ->assertDontSeeHtml('aria-label="Skrýt poslední záznamy"');

    $this->get('/admin/time-entries')->assertOk()->assertSee('data-pref="closed"', false);
});

it('stores the open choice and names the toggle accordingly', function (): void {
    $this->admin->forceFill(['time_panel_open' => false])->save();

    Livewire::test(RecentEntriesPanel::class)->call('setOpen', true)->assertSet('open', true);

    expect($this->admin->refresh()->time_panel_open)->toBeTrue();

    $this->get('/admin')
        ->assertOk()
        ->assertSee('data-pref="open"', false);

    Livewire::test(TimerBar::class)->assertSeeHtml('aria-label="Skrýt poslední záznamy"');
});

it('keeps the toggle of the bar and the panel client-side below the docked width', function (): void {
    $html = $this->get('/admin')->assertOk()->getContent();

    // The bar dispatches a browser event; the panel's Alpine state listens to it and reports its
    // state back. Only a toggle at docked width calls the server, so the overlay is never stored.
    expect($html)->toContain('kokpit-time-panel-toggle')
        ->and($html)->toContain('kokpit-time-panel-toggle.window')
        ->and($html)->toContain('kokpit-time-panel-state')
        ->and($html)->toContain('matchMedia')
        ->and($html)->toContain('x-on:keydown.escape.window');
});

it('lets only the Admin write the preference and only on the own row', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $other = Canary::admin();

    expect(fn () => app(SetTimePanelOpen::class)->handle($partner, true))->toThrow(AuthorizationException::class);

    app(SetTimePanelOpen::class)->handle($this->admin, false);

    expect($partner->refresh()->time_panel_open)->toBeNull()
        ->and($other->refresh()->time_panel_open)->toBeNull()
        ->and($this->admin->refresh()->time_panel_open)->toBeFalse();
});

it('refuses a forged setOpen by a Partner and writes nothing', function (): void {
    $forged = Livewire::test(RecentEntriesPanel::class);
    $partner = Canary::partnerFor(Canary::twoClients()[0]);

    $this->actingAs($partner);

    $forged->call('setOpen', false)->assertForbidden();

    expect($partner->refresh()->time_panel_open)->toBeNull()
        ->and($this->admin->refresh()->time_panel_open)->toBeNull();
});

/**
 * One finished entry on each of the given days before today, 10:00 Prague, one hour long.
 *
 * @param  list<int>  $daysAgo
 */
function panelDays(Client $client, array $daysAgo): void
{
    foreach ($daysAgo as $ago) {
        panelEntry(CarbonImmutable::parse('2026-10-09 10:00', 'Europe/Prague')->subDays($ago)->format('Y-m-d H:i'), 60, ['client_id' => $client->id]);
    }
}

it('shows the seven newest days that have entries and appends the rest with the older button', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    // Ten distinct days, with quiet days in between: 7 days that contain entries, not 7 calendar days.
    panelDays($client, [0, 1, 3, 4, 6, 8, 9, 12, 15, 20]);

    $component = Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Načíst starší záznamy');

    expect($component->instance()->recent['days'])->toHaveCount(7)
        ->and($component->instance()->recent['has_more'])->toBeTrue();

    $component->call('loadOlder')->assertDontSee('Načíst starší záznamy');

    expect($component->instance()->recent['days'])->toHaveCount(10)
        ->and($component->instance()->recent['has_more'])->toBeFalse();
});

it('disappears the older button when exactly one page of days exists', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelDays($client, [0, 1, 2, 3, 4, 5, 6]);

    Livewire::test(RecentEntriesPanel::class)->assertDontSee('Načíst starší záznamy');
});

it('shows one day with one entry as a heading, a total and one row', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelEntry('2026-10-09 08:00', 95, ['client_id' => $client->id]);

    $component = Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Dnes')
        ->assertSee('1:35')
        ->assertDontSee('Načíst starší záznamy');

    expect($component->instance()->recent['days'][0]['entries'])->toHaveCount(1);
});

it('pages through the days with the same keys after a refresh event', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelDays($client, [0, 1, 2, 3, 4, 5, 6, 7, 8]);

    $component = Livewire::test(RecentEntriesPanel::class)->call('loadOlder');

    // A new entry saved elsewhere shows up on refresh and the number of shown days is kept.
    panelEntry('2026-10-09 11:00', 30, ['client_id' => $client->id]);
    $component->dispatch('time-entry-saved');

    $days = $component->instance()->recent['days'];

    expect($days)->toHaveCount(9)
        ->and($days[0]['entries'])->toHaveCount(2)
        ->and($component->instance()->visibleDays)->toBe(14);
});

it('puts the year on a day heading outside the current year', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelEntry('2025-12-30 10:00', 60, ['client_id' => $client->id]);
    panelEntry('2026-10-05 10:00', 60, ['client_id' => $client->id]);

    $labels = array_column(Livewire::test(RecentEntriesPanel::class)->instance()->recent['days'], 'label');

    expect($labels)->toBe(['Pondělí 5. 10.', 'Úterý 30. 12. 2025']);
});

it('lists an entry from 23:30 to 00:30 Prague under the day it started', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelEntry('2026-10-07 23:30', 60, ['client_id' => $client->id]);

    $days = Livewire::test(RecentEntriesPanel::class)->instance()->recent['days'];

    expect($days)->toHaveCount(1)
        ->and($days[0]['label'])->toBe('Středa 7. 10.')
        ->and($days[0]['total_seconds'])->toBe(3600);
});

it('counts a 25 hour day by its Prague date and never as 24 hours', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'Europe/Prague'));
    $client = Client::factory()->create(['name' => 'Cihla']);

    // The clocks go back on Sunday 25 October 2026: that day lasts 25 hours.
    panelEntry('2026-10-24 23:30', 40, ['client_id' => $client->id]);
    panelEntry('2026-10-25 00:15', 20, ['client_id' => $client->id]);
    panelEntry('2026-10-25 23:30', 30, ['client_id' => $client->id]);
    panelEntry('2026-10-26 00:10', 10, ['client_id' => $client->id]);

    $days = Livewire::test(RecentEntriesPanel::class)->instance()->recent['days'];

    expect(array_column($days, 'date'))->toBe(['2026-10-26', '2026-10-25', '2026-10-24'])
        ->and(array_column($days, 'total_seconds'))->toBe([600, 3000, 2400]);
});

it('reads the days in a number of queries that does not depend on the number of entries', function (): void {
    $w = panelWorld();
    $task = $w['task'];
    $client = $w['client'];

    $count = static function (): int {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        Livewire::test(RecentEntriesPanel::class)->assertSee('Dnes');

        return $queries;
    };

    $entriesOf = static function (int $perDay) use ($task): void {
        foreach (range(0, 6) as $ago) {
            foreach (range(1, $perDay) as $n) {
                $start = CarbonImmutable::parse('2026-10-09 06:00', 'Europe/Prague')->utc()->subDays($ago)->addMinutes(($n - 1) * 20);
                TimeEntry::factory()->forTask($task)->create([
                    'user_id' => test()->admin->id,
                    'started_at' => $start,
                    'ended_at' => $start->addMinutes(15),
                ]);
            }
        }
    };

    $entriesOf(3);
    $few = $count();

    $entriesOf(30);
    $many = $count();

    expect(TimeEntry::query()->count())->toBe(7 * 33)
        ->and($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});

it('renders the empty state with its two sentences and no way to log time', function (): void {
    Client::factory()->create(['name' => 'Cihla']);

    Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Zatím tu nejsou žádné záznamy')
        ->assertSee('Spusťte časovač výše. Záznam můžete přidat i ručně v nabídce Časové záznamy.')
        ->assertDontSee('Log time')
        ->assertDontSee('Načíst starší záznamy')
        // The timer block above stays usable.
        ->assertSee('Spustit časovač')
        ->assertSee('0:00:00')
        ->set('clientId', Client::query()->sole()->id)
        ->call('start')
        ->assertNotified('Časovač byl spuštěn');
});

it('shows the danger callout above the readout when the timer runs too long', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'started_at' => now()->subHours(12)->subMinute()]);

    Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Časovač běží příliš dlouho')
        ->assertSee('Běží déle než 12 h. Zkontrolujte, jestli ho nemáte zastavit.')
        ->assertSeeHtml('data-state="too-long"')
        ->assertSee('12:01:00');

    // Under the threshold there is no callout, and the entry is never stopped by the panel.
    $entry->forceFill(['started_at' => now()->subHours(2)])->save();

    Livewire::test(RecentEntriesPanel::class)
        ->assertDontSee('Časovač běží příliš dlouho')
        ->assertSeeHtml('data-state="running"');

    expect($entry->refresh()->ended_at)->toBeNull();
});

it('shows the same race toast as the bar and tells a stale stop that nothing runs', function (): void {
    $client = Client::factory()->create();

    $this->app->instance(StartTimer::class, new class
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function handle(User $actor, array $data): never
        {
            throw new TimerRaceLost;
        }
    });

    Livewire::test(RecentEntriesPanel::class)
        ->set('clientId', $client->id)
        ->call('start')
        ->assertNotified('Časovač se nepodařilo spustit, protože se současně změnil jiný. Zkuste to znovu.')
        ->assertNotDispatched('timer-started');

    $old = TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);
    $newer = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    Livewire::test(RecentEntriesPanel::class)->call('stop', $old->id)->assertNotified('Žádný časovač neběží.');

    expect($newer->refresh()->ended_at)->toBeNull();
});

it('keeps a long title to one line and a long description to two lines with the full text as tooltip', function (): void {
    $name = str_repeat('Dlouhý název ', 10);
    $client = Client::factory()->create(['name' => $name]);
    panelEntry('2026-10-09 08:00', 30, ['client_id' => $client->id, 'description' => str_repeat('Example long text ', 12)]);

    Livewire::test(RecentEntriesPanel::class)
        ->assertSeeHtml('kokpit-panel-row-title')
        ->assertSeeHtml('white-space: nowrap')
        ->assertSeeHtml('-webkit-line-clamp: 2')
        ->assertSeeHtml('title="'.$name.'"');
});

it('opens the entries list from the footer link and offers no billed marks', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelEntry('2026-10-09 08:00', 30, ['client_id' => $client->id, 'billing_state' => 'billed', 'billed_at' => now()]);

    Livewire::test(RecentEntriesPanel::class)
        ->assertSee('Zobrazit všechny záznamy')
        ->assertSeeHtml('/admin/time-entries"')
        ->assertDontSeeHtml('lock-closed');
});

it('reads the days before a cursor and says whether older ones exist', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    panelDays($client, [0, 2, 5, 9]);

    $page = app(RecentEntries::class)->days($this->admin, '2026-10-07', 2);

    expect(array_column($page['days'], 'date'))->toBe(['2026-10-04', '2026-09-30'])
        ->and($page['has_more'])->toBeFalse();

    $first = app(RecentEntries::class)->days($this->admin, null, 2);

    expect(array_column($first['days'], 'date'))->toBe(['2026-10-09', '2026-10-07'])
        ->and($first['has_more'])->toBeTrue();
});

it('never lists the entries of another user', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    TimeEntry::factory()->create([
        'user_id' => Canary::admin()->id,
        'client_id' => $client->id,
        'started_at' => now()->subHours(3),
        'ended_at' => now()->subHours(2),
    ]);

    expect(app(RecentEntries::class)->days($this->admin, null)['days'])->toBe([]);
});
