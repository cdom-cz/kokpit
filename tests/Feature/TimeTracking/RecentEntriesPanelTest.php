<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\SetTimePanelOpen;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Livewire\TimeTracking\RecentEntriesPanel;
use App\Livewire\TimeTracking\TimerBar;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
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
    $start = CarbonImmutable::parse($startedAt, 'Europe/Prague');

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
