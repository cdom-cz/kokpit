<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\EntryContextOptions;
use App\Domain\TimeTracking\TimerRaceLost;
use App\Livewire\TimeTracking\TimerBar;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The timer in the top bar (TI-01, D-01, D-02): the quick start, the running pill, the stop, and
 * the Admin-only guard on the page and on every Livewire request. Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('shows the idle bar on a panel page of the Admin', function (): void {
    Client::factory()->create(['name' => 'Cihla']);

    $this->get('/admin')->assertOk()->assertSee('Spustit časovač');
});

it('starts a timer for the preselected client and announces it to the other timer surfaces', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    Livewire::test(TimerBar::class)
        ->assertSet('clientId', $client->id)
        ->set('description', 'Example first work')
        ->call('start')
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn')
        ->assertSet('description', '');

    $running = TimeEntry::query()->whereNull('ended_at')->sole();

    expect($running->client_id)->toBe($client->id)
        ->and($running->user_id)->toBe($this->admin->id)
        ->and($running->description)->toBe('Example first work');
});

it('asks for a client when none is chosen and starts nothing', function (): void {
    Livewire::test(TimerBar::class)
        ->set('clientId', null)
        ->call('start')
        ->assertNotified('Vyberte klienta.');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('shows the running pill with the warning colour, the clock and the stop button', function (): void {
    $client = Client::factory()->create();
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    Livewire::test(TimerBar::class)
        ->assertSeeHtml('fi-color-warning')
        ->assertSeeHtml('role="timer"')
        ->assertSeeHtml('aria-live="off"')
        ->assertSee('Zastavit časovač')
        ->assertDontSee('Vyberte klienta');
});

it('stops the running timer without a question and tells the duration', function (): void {
    $client = Client::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    Livewire::test(TimerBar::class)
        ->call('stop', $entry->id)
        ->assertDispatched('timer-stopped')
        ->assertNotified('Časovač byl zastaven')
        ->assertDontSeeHtml('role="timer"')
        ->assertSee('Spustit časovač');

    expect($entry->refresh()->ended_at)->not->toBeNull();
});

it('stops the running timer when a new one starts and says so', function (): void {
    $client = Client::factory()->create();
    $old = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    Livewire::test(TimerBar::class)
        ->set('clientId', $client->id)
        ->call('start')
        ->assertNotified('Časovač byl spuštěn');

    expect($old->refresh()->ended_at)->not->toBeNull()
        ->and(TimeEntry::query()->whereNull('ended_at')->count())->toBe(1);
});

it('renders nothing of the timer on the pages of a Partner', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $this->actingAs($partner);

    foreach (['/admin', '/admin/my-tasks'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect(mb_stripos((string) $html, 'časovač'))->toBeFalse($url.' must hold no timer text')
            ->and(mb_stripos((string) $html, 'timer-bar'))->toBeFalse($url.' must hold no timer component');
    }
});

it('refuses a Partner who mounts the timer bar', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    Livewire::test(TimerBar::class)->assertForbidden();
});

it('refuses a forged request by a Partner on a component the Admin mounted, and writes nothing', function (): void {
    $client = Client::factory()->create();
    $forgedStart = Livewire::test(TimerBar::class)->set('clientId', $client->id);
    $forgedStop = Livewire::test(TimerBar::class);

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    $forgedStart->call('start')->assertForbidden();
    $forgedStop->call('stop')->assertForbidden();

    expect(TimeEntry::query()->count())->toBe(0);
});

it('keeps only scalars in the snapshot of the component', function (): void {
    $client = Client::factory()->create();
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'description' => 'Example work']);

    $component = Livewire::test(TimerBar::class)->mountAction('completeRunningEntry');
    $data = $component->snapshot['data'];
    $json = (string) json_encode($data);

    expect($data)->toHaveKeys(['clientId', 'description'])
        ->and($data['clientId'])->toBeString()
        // A model would travel as a tuple with the model marker; a rate or a price has no place here.
        ->and($json)->not->toContain('"s":"mdl"')
        ->and($json)->not->toContain('rate')
        ->and($json)->not->toContain('price');
});

it('offers the five most recently used clients first, then the others in Czech order, each once', function (): void {
    $names = ['Dub', 'Cihla', 'Čáp', 'Ebr', 'Fík', 'Gorila', 'Cizrna'];
    $clients = [];

    foreach ($names as $index => $name) {
        $clients[$name] = Client::factory()->create(['name' => $name]);
        // The later the client in the list, the older its only entry; the last two have none within the five.
        TimeEntry::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $clients[$name]->id,
            'started_at' => now()->subDays($index + 1),
            'ended_at' => now()->subDays($index + 1)->addHour(),
        ]);
    }

    $archived = Client::factory()->create(['name' => 'Archiv']);
    TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $archived->id, 'started_at' => now()->subHours(5), 'ended_at' => now()->subHours(4)]);
    app(ArchiveClient::class)->handle($archived);

    $groups = app(EntryContextOptions::class)->timerClients($this->admin);

    expect(array_values($groups['recent']))->toBe(['Dub', 'Cihla', 'Čáp', 'Ebr', 'Fík'])
        ->and(array_values($groups['all']))->toBe(['Cizrna', 'Gorila'])
        ->and($groups['preselected'])->toBeNull();

    $this->travelBack();
    TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $clients['Gorila']->id, 'started_at' => now()->subMinutes(30), 'ended_at' => now()->subMinutes(20)]);

    $groups = app(EntryContextOptions::class)->timerClients($this->admin);

    expect(array_values($groups['recent']))->toBe(['Gorila', 'Dub', 'Cihla', 'Čáp', 'Ebr'])
        ->and(array_values($groups['all']))->toBe(['Cizrna', 'Fík'])
        ->and($groups['preselected'])->toBe($clients['Gorila']->id);

    Livewire::test(TimerBar::class)
        ->assertSeeHtml('label="Naposledy použití"')
        ->assertSeeHtml('label="Všichni klienti"')
        ->assertSee('Vyberte klienta')
        ->assertDontSee('Archiv');
});

it('preselects nothing when the client of the latest entry is archived', function (): void {
    $client = Client::factory()->create();
    TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);
    app(ArchiveClient::class)->handle($client);

    expect(app(EntryContextOptions::class)->timerClients($this->admin)['preselected'])->toBeNull();
});

it('refuses a forged update posted to the Livewire endpoint by a Partner', function (): void {
    $client = Client::factory()->create();
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    // The snapshot the Admin's page holds, as the browser would post it.
    $html = $this->get('/admin')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]*timer-bar[^"]*)"/', (string) $html, $match);

    expect($match)->not->toBeEmpty();

    $snapshot = html_entity_decode($match[1], ENT_QUOTES);

    $payload = ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [],
    ]]];

    // The same request by the Admin is accepted, so the refusal below is the guard and not a malformed post.
    $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk();

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    $this->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => 'stop', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertForbidden();

    // Read back as the Admin: a Partner reads no time entries at all.
    $this->actingAs($this->admin);

    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(1);
});

it('turns the pill to the danger state exactly at the threshold, without a reload', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $client = Client::factory()->create();
    TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'started_at' => now(), 'ended_at' => null]);

    $component = Livewire::test(TimerBar::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 19:59:59', 'UTC'));
    $component->call('refreshState')
        ->assertSeeHtml('fi-color-warning')
        ->assertDontSeeHtml('fi-color-danger')
        ->assertSeeHtml('data-state="running"')
        ->assertSee('11:59:59');

    $this->travelTo(CarbonImmutable::parse('2026-10-12 20:00:00', 'UTC'));
    $component->call('refreshState')
        ->assertSeeHtml('fi-color-danger')
        ->assertDontSeeHtml('fi-color-warning')
        ->assertSeeHtml('data-state="too-long"')
        ->assertSee('Časovač běží déle než 12 h. Zkontrolujte, jestli ho nemáte zastavit.')
        ->assertSee('12:00:00');

    // Nothing was stored or stopped by the flag.
    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(1);
});

it('reads the threshold from the configuration on every render', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $client = Client::factory()->create();
    TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'started_at' => now(), 'ended_at' => null]);
    config(['kokpit.time.long_running_hours' => 2]);

    $component = Livewire::test(TimerBar::class)->assertSeeHtml('fi-color-warning');

    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    $component->call('refreshState')
        ->assertSeeHtml('fi-color-danger')
        ->assertSee('Časovač běží déle než 2 h.');
});

it('shows the running state when another surface starts a timer and announces the event', function (): void {
    $client = Client::factory()->create();
    $component = Livewire::test(TimerBar::class)->assertDontSeeHtml('role="timer"');

    app(StartTimer::class)->handle($this->admin, ['client_id' => $client->id, 'description' => 'Example started elsewhere']);

    $component->dispatch('timer-started')->assertSeeHtml('role="timer"');
});

it('goes idle when another surface stops the timer', function (): void {
    $client = Client::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);
    $component = Livewire::test(TimerBar::class)->assertSeeHtml('role="timer"');

    $entry->forceFill(['ended_at' => now()])->save();

    $component->dispatch('timer-stopped')->assertDontSeeHtml('role="timer"');
});

it('re-reads the description when an entry was saved', function (): void {
    $client = Client::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'description' => 'Example before']);
    $component = Livewire::test(TimerBar::class)->assertSee('Example before');

    $entry->forceFill(['description' => 'Example after'])->save();

    $component->dispatch('time-entry-saved')->assertSee('Example after')->assertDontSee('Example before');
});

it('also refreshes on the deletion of an entry', function (): void {
    $client = Client::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);
    $component = Livewire::test(TimerBar::class)->assertSeeHtml('role="timer"');

    $entry->delete();

    $component->dispatch('time-entry-deleted')->assertDontSeeHtml('role="timer"');
});

it('shows the race toast and re-reads the state when a concurrent start won', function (): void {
    $client = Client::factory()->create();

    // StartTimer is final, so the container gets a stand-in that loses the race.
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

    Livewire::test(TimerBar::class)
        ->set('clientId', $client->id)
        ->call('start')
        ->assertNotified('Časovač se nepodařilo spustit, protože se současně změnil jiný. Zkuste to znovu.')
        ->assertNotDispatched('timer-started');
});

it('tells a stale stop that nothing runs and renders the idle state', function (): void {
    $client = Client::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);
    $component = Livewire::test(TimerBar::class)->assertSeeHtml('role="timer"');

    // Stopped in another tab meanwhile.
    $entry->forceFill(['ended_at' => now()])->save();
    $stoppedAt = $entry->refresh()->ended_at;

    $component->call('stop', $entry->id)
        ->assertNotified('Žádný časovač neběží.')
        ->assertDontSeeHtml('role="timer"')
        ->assertSee('Spustit časovač');

    expect($entry->refresh()->ended_at?->equalTo($stoppedAt))->toBeTrue();
});

it('never stops a newer timer from a stale stop button', function (): void {
    $client = Client::factory()->create();
    $old = TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(30)]);
    $newer = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    Livewire::test(TimerBar::class)->call('stop', $old->id)->assertNotified('Žádný časovač neběží.');

    expect($newer->refresh()->ended_at)->toBeNull();
});

it('offers only the no-client text and the link while no client exists', function (): void {
    Livewire::test(TimerBar::class)
        ->assertSee('Nejdřív vytvořte klienta, ke kterému se bude čas zapisovat.')
        ->assertSee('Klienti')
        ->assertSeeHtml('/admin/clients')
        ->assertDontSeeHtml('type="submit"')
        ->assertDontSee('Na čem pracujete?');
});

it('keeps the full description in the tooltip of the pill', function (): void {
    $client = Client::factory()->create();
    $text = 'Example '.str_repeat('long description ', 12).'end';
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'description' => $text]);

    Livewire::test(TimerBar::class)->assertSeeHtml('title="'.e($text).'"');
});

it('escapes a description with markup', function (): void {
    $client = Client::factory()->create();
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'description' => '<script>alert(1)</script>']);

    Livewire::test(TimerBar::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('shows a running entry with only a client as a normal state and its missing parts as dashes', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'description' => null]);

    Livewire::test(TimerBar::class)
        ->assertSeeHtml('fi-color-warning')
        ->assertSee('Cihla')
        ->assertSee('—');
});

it('shows hours without an upper bound', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $client = Client::factory()->create();
    TimeEntry::factory()->create(['user_id' => $this->admin->id, 'client_id' => $client->id, 'started_at' => now()->subHours(123)->subMinutes(45)->subSeconds(7), 'ended_at' => null]);

    Livewire::test(TimerBar::class)->assertSee('123:45:07');
});

/**
 * A client with a project and a task, created like the screens do.
 *
 * @param  array<string, mixed>  $billing
 * @return array{client: Client, project: Project, task: Task}
 */
function timerBarWorld(string $clientName = 'Cihla', array $billing = []): array
{
    $client = Client::factory()->create(['name' => $clientName]);
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Example project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
    ]);
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example task']);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle(test()->admin, $task, $billing)->refresh();
    }

    return ['client' => $client, 'project' => $project, 'task' => $task];
}

it('opens "Doplnit záznam" filled with the running entry and keeps project and task empty', function (): void {
    $w = timerBarWorld();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $w['client']->id, 'description' => 'Example before']);

    Livewire::test(TimerBar::class)
        ->assertSee('Doplnit záznam')
        ->mountAction('completeRunningEntry')
        ->assertMountedActionModalSee('Doplnit běžící záznam')
        ->assertActionDataSet(static fn (array $state): bool => $state['client_id'] === $w['client']->id
            && $state['project_id'] === null
            && $state['task_id'] === null
            && $state['description'] === 'Example before'
            && filled($state['started_at'])
            && $state['billable'] === true);

    expect($entry->refresh()->ended_at)->toBeNull();
});

it('fills in the task and the description of the running entry while it keeps running', function (): void {
    $w = timerBarWorld();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $w['client']->id, 'description' => null]);

    Livewire::test(TimerBar::class)
        ->callAction('completeRunningEntry', ['task_id' => $w['task']->id, 'description' => 'Example filled in later'])
        ->assertHasNoErrors()
        ->assertNotified('Záznam byl uložen')
        ->assertDispatched('time-entry-saved')
        ->assertSee($w['task']->reference);

    $entry->refresh();

    expect($entry->ended_at)->toBeNull()
        ->and($entry->task_id)->toBe($w['task']->id)
        ->and($entry->project_id)->toBe($w['project']->id)
        ->and($entry->client_id)->toBe($w['client']->id)
        ->and($entry->description)->toBe('Example filled in later')
        ->and(TimeEntry::query()->whereNull('ended_at')->count())->toBe(1);
});

it('refuses a forged task of another client as a field error under Úkol and changes nothing', function (): void {
    $mine = timerBarWorld('Cihla');
    $other = timerBarWorld('Dub');
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $mine['client']->id, 'description' => 'Example before']);

    // The whole state is replaced at once, so the cascade hooks do not tidy the forged ids.
    $component = Livewire::test(TimerBar::class)
        ->mountAction('completeRunningEntry')
        ->set('mountedActions.0.data', [
            'client_id' => $mine['client']->id,
            'project_id' => null,
            'task_id' => $other['task']->id,
            'description' => 'Example forged',
            'started_at' => $entry->started_at->setTimezone('Europe/Prague')->format('Y-m-d H:i:s'),
            'billable' => true,
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['task_id']);

    expect($component->errors()->get('mountedActions.0.data.task_id'))->toContain('Klient, projekt a úkol k sobě nepatří. Vyberte je znovu.');

    $entry->refresh();

    expect($entry->task_id)->toBeNull()
        ->and($entry->client_id)->toBe($mine['client']->id)
        ->and($entry->description)->toBe('Example before');
});

it('puts a field error of the Action under its field in the modal', function (): void {
    $w = timerBarWorld();
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $w['client']->id]);

    // The form already refuses a bad combination by its options; a change that slips in between the
    // form check and the write is answered by the Action, with a bare key that must land in the modal.
    // UpdateTimeEntry is final, so the container gets a stand-in that refuses.
    $this->app->instance(UpdateTimeEntry::class, new class
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function handle(User $actor, TimeEntry $entry, array $data): never
        {
            throw ValidationException::withMessages(['task_id' => 'Example refusal of the Action.']);
        }
    });

    $component = Livewire::test(TimerBar::class)
        ->mountAction('completeRunningEntry')
        ->callMountedAction()
        ->assertHasActionErrors(['task_id']);

    expect($component->errors()->get('mountedActions.0.data.task_id'))->toContain('Example refusal of the Action.');
});

it('hides "Doplnit záznam" and does nothing while no timer runs', function (): void {
    Client::factory()->create();

    Livewire::test(TimerBar::class)
        ->assertDontSee('Doplnit záznam')
        ->mountAction('completeRunningEntry')
        ->assertActionNotMounted('completeRunningEntry');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('presets Fakturovatelné off when a non-billable task is chosen in the modal', function (): void {
    $w = timerBarWorld(billing: ['billing_type' => 'non_billable']);
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $w['client']->id]);

    Livewire::test(TimerBar::class)
        ->mountAction('completeRunningEntry')
        ->fillForm(['task_id' => $w['task']->id])
        ->assertActionDataSet(static fn (array $state): bool => $state['billable'] === false && $state['project_id'] === $w['project']->id);

    expect($entry->refresh()->billable)->toBeTrue();
});

it('keeps the start of the running entry editable in the modal and the entry running', function (): void {
    $w = timerBarWorld();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $w['client']->id]);
    $newStart = now()->subHours(3)->startOfSecond();

    Livewire::test(TimerBar::class)
        ->mountAction('completeRunningEntry')
        ->fillForm(['started_at' => $newStart->setTimezone('Europe/Prague')->format('Y-m-d H:i:s')])
        ->callMountedAction()
        ->assertHasNoErrors();

    $entry->refresh();

    expect($entry->ended_at)->toBeNull()
        ->and($entry->started_at->equalTo($newStart))->toBeTrue();
});

it('refuses the modal to a Partner who forges the action', function (): void {
    $w = timerBarWorld();
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $w['client']->id]);
    $component = Livewire::test(TimerBar::class)->mountAction('completeRunningEntry');

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    $component->callMountedAction()->assertForbidden();
});
