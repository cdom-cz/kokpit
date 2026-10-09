<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
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

/**
 * Two clients, each with a project and a task, for the cascade tests.
 *
 * @return array{a: Client, b: Client, pa: Project, pb: Project, pa2: Project, ta: Task, tb: Task, ta2: Task}
 */
function entryFormWorld(): array
{
    $admin = test()->admin;
    $a = Client::factory()->create(['name' => 'Cihla']);
    $b = Client::factory()->create(['name' => 'Dub']);
    $project = static fn (Client $client, string $key): Project => app(PartnerContext::class)->runAsSystem(
        static fn (): Project => Project::factory()->create(['client_id' => $client->id, 'key' => $key]),
    );
    $pa = $project($a, 'AAA');
    $pa2 = $project($a, 'AAB');
    $pb = $project($b, 'BBB');
    $create = static fn (Project $project, string $title): Task => app(CreateTask::class)->handle($admin, $project, ['title' => $title]);

    return ['a' => $a, 'b' => $b, 'pa' => $pa, 'pb' => $pb, 'pa2' => $pa2, 'ta' => $create($pa, 'Example a'), 'tb' => $create($pb, 'Example b'), 'ta2' => $create($pa2, 'Example a2')];
}

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

it('fills the project and the client when a task is chosen', function (): void {
    $w = entryFormWorld();

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $w['tb']->id)
        ->assertSet('data.project_id', $w['pb']->id)
        ->assertSet('data.client_id', $w['b']->id);
});

it('switches the client and clears a task of another project when a project is chosen', function (): void {
    $w = entryFormWorld();

    Livewire::test(CreateTimeEntry::class)
        ->set('data.client_id', $w['a']->id)
        ->set('data.task_id', $w['ta']->id)
        ->set('data.project_id', $w['pb']->id)
        ->assertSet('data.client_id', $w['b']->id)
        ->assertSet('data.task_id', null)
        ->assertSet('data.project_id', $w['pb']->id);
});

it('keeps a task of the chosen project', function (): void {
    $w = entryFormWorld();

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $w['ta']->id)
        ->set('data.project_id', $w['pa']->id)
        ->assertSet('data.task_id', $w['ta']->id)
        ->assertSet('data.client_id', $w['a']->id);
});

it('clears a project and a task of another client when the client changes', function (): void {
    $w = entryFormWorld();

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $w['ta']->id)
        ->set('data.client_id', $w['b']->id)
        ->assertSet('data.project_id', null)
        ->assertSet('data.task_id', null)
        ->assertSet('data.client_id', $w['b']->id);
});

it('keeps the client when the project is cleared', function (): void {
    $w = entryFormWorld();

    Livewire::test(CreateTimeEntry::class)
        ->set('data.project_id', $w['pa']->id)
        ->set('data.project_id', null)
        ->assertSet('data.client_id', $w['a']->id)
        ->assertSet('data.project_id', null);
});

it('offers only the projects and tasks of the chosen client', function (): void {
    $w = entryFormWorld();

    $options = static fn ($component, string $field): array => array_keys($component->instance()->form->getComponent($field)?->getOptions() ?? []);

    $component = Livewire::test(CreateTimeEntry::class)->set('data.client_id', $w['a']->id);

    expect($options($component, 'project_id'))->toEqualCanonicalizing([$w['pa']->id, $w['pa2']->id])
        ->and($options($component, 'task_id'))->toEqualCanonicalizing([$w['ta']->id, $w['ta2']->id]);

    $component->set('data.project_id', $w['pa2']->id);

    expect($options($component, 'task_id'))->toBe([$w['ta2']->id]);
});

it('saves an entry with a client, a project and a task', function (): void {
    $w = entryFormWorld();

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $w['ta']->id)
        ->fillForm(['started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 10:30:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = TimeEntry::query()->firstOrFail();

    expect($entry->task_id)->toBe($w['ta']->id)
        ->and($entry->project_id)->toBe($w['pa']->id)
        ->and($entry->client_id)->toBe($w['a']->id);
});

it('refuses a forged combination as a field error and stores nothing', function (): void {
    $w = entryFormWorld();

    // The whole state is replaced at once, so the cascade hooks do not tidy the forged ids.
    $component = Livewire::test(CreateTimeEntry::class)
        ->set('data', [
            'client_id' => $w['a']->id,
            'project_id' => $w['pa2']->id,
            'task_id' => $w['ta']->id,
            'description' => null,
            'started_at' => '2026-10-12 10:00:00',
            'ended_at' => '2026-10-12 10:30:00',
            'billable' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['task_id']);

    expect($component->errors()->get('data.task_id'))->toContain('Klient, projekt a úkol k sobě nepatří. Vyberte je znovu.')
        ->and(TimeEntry::query()->count())->toBe(0);
});

it('reports a missing client as a field error under the client and stores nothing', function (): void {
    $component = Livewire::test(CreateTimeEntry::class)
        ->fillForm(['started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 10:30:00'])
        ->call('create')
        ->assertHasFormErrors(['client_id']);

    expect($component->errors()->get('data.client_id'))->toContain('Vyberte klienta.')
        ->and(TimeEntry::query()->count())->toBe(0);
});
