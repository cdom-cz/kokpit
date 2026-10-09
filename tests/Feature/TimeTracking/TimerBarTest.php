<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\EntryContextOptions;
use App\Domain\TimeTracking\TimerRaceLost;
use App\Livewire\TimeTracking\TimerBar;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id, 'client_id' => $client->id]);

    $component = Livewire::test(TimerBar::class);

    expect(array_keys($component->snapshot['data']))->toEqualCanonicalizing(['clientId', 'description'])
        ->and($component->snapshot['data']['clientId'])->toBeString();
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
