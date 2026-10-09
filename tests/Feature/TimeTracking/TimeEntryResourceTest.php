<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource;
use App\Filament\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin time entry screens (TI-02, TI-03): list, create page and entry page, and the refusal
 * of every time entry route to a Partner. Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('shows the empty state of the list and keeps "Nový záznam" reachable', function (): void {
    Livewire::test(ListTimeEntries::class)
        ->assertSee('Zatím tu nejsou žádné časové záznamy')
        ->assertSee('Spusťte časovač nebo vytvořte záznam tlačítkem Nový záznam.')
        ->assertSee('Nový záznam');

    $this->get('/admin/time-entries/create')->assertOk()->assertSee('Nový časový záznam');
});

it('saves a client-only entry from the create page and shows it in the list and on its page', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);

    Livewire::test(CreateTimeEntry::class)
        ->fillForm([
            'client_id' => $client->id,
            'description' => 'Example first work',
            'started_at' => '2026-10-12 10:00:00',
            'ended_at' => '2026-10-12 11:30:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Záznam byl uložen')
        ->assertRedirect();

    $entry = TimeEntry::query()->firstOrFail();

    expect($entry->client_id)->toBe($client->id)
        ->and($entry->project_id)->toBeNull()
        ->and($entry->task_id)->toBeNull()
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->duration_seconds)->toBe(5400)
        ->and($entry->billable)->toBeTrue();

    Livewire::test(ListTimeEntries::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertSee('Cihla')
        ->assertTableColumnStateSet('elapsed_seconds', '1:30', $entry);

    Livewire::test(ViewTimeEntry::class, ['record' => $entry->id])
        ->assertSee('Cihla')
        ->assertSee('1:30:00')
        ->assertSee('Example first work');
});

it('writes the picked Prague wall-clock times as UTC instants', function (): void {
    $client = Client::factory()->create();

    Livewire::test(CreateTimeEntry::class)
        ->fillForm(['client_id' => $client->id, 'started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 11:30:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = TimeEntry::query()->firstOrFail();

    expect($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00')
        ->and($entry->ended_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 09:30:00');
});

it('lists the newest start first and sorts by the duration', function (): void {
    $client = Client::factory()->create();
    $older = TimeEntry::factory()->create(['client_id' => $client->id, 'started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)->addHours(3)]);
    $newer = TimeEntry::factory()->create(['client_id' => $client->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour()]);

    Livewire::test(ListTimeEntries::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->sortTable('elapsed_seconds', 'desc')
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

it('shows a running entry at its elapsed time and a missing project and task as a dash', function (): void {
    $this->travelTo(now()->startOfSecond());

    $entry = TimeEntry::factory()->running()->create(['started_at' => now()->subSeconds(3725)]);

    Livewire::test(ListTimeEntries::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertTableColumnStateSet('elapsed_seconds', '1:02', $entry)
        ->assertSee('Běží')
        ->assertSee('—');

    expect(TimeEntryResource::timeRangeText($entry))->toEndWith('–');
});

it('limits the description to 80 characters and shows 25 rows per page', function (): void {
    $client = Client::factory()->create();
    $long = str_repeat('Řeka ', 30);
    TimeEntry::factory()->count(26)->create(['client_id' => $client->id, 'description' => $long]);

    $component = Livewire::test(ListTimeEntries::class)
        ->assertSee(mb_substr(trim($long), 0, 79))
        ->assertDontSee(trim($long));

    expect($component->instance()->getTableRecords()->count())->toBe(25)
        ->and($component->instance()->getAllTableRecordsCount())->toBe(26);
});

it('refuses a Partner every time entry route', function (): void {
    $client = Client::factory()->create();
    $entry = TimeEntry::factory()->create(['client_id' => $client->id]);

    $this->actingAs(Canary::partnerFor($client->id));

    $this->get('/admin/time-entries')->assertForbidden();
    $this->get('/admin/time-entries/create')->assertForbidden();
    $this->get('/admin/time-entries/'.$entry->id)->assertForbidden();
});

it('is not globally searchable', function (): void {
    expect(TimeEntryResource::canGloballySearch())->toBeFalse();
});
