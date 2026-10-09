<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource;
use App\Filament\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\EditTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
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
    $project = static fn (Client $client, string $key): Project => app(CreateProject::class)->handle($client, [
        'name' => 'Example project '.$key,
        'key' => $key,
        'billing_type' => 'hourly',
    ]);
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
    $this->get('/admin/time-entries/'.$entry->id.'/edit')->assertForbidden();
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

/**
 * A task of a new project, with the given billing keys written through UpdateTask like the edit page does.
 *
 * @param  array<string, mixed>  $projectData
 * @param  array<string, mixed>  $billing
 */
function entryFormTask(array $projectData = [], array $billing = []): Task
{
    $project = app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example billing project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
        ...$projectData,
    ]);
    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example billing task']);

    return $billing === [] ? $task : app(UpdateTask::class)->handle(test()->admin, $task, $billing)->refresh();
}

it('edits the description and the times of a finished entry through the Action', function (): void {
    $entry = TimeEntry::factory()->create(['description' => 'Example before']);

    Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->fillForm(['description' => 'Example after', 'started_at' => '2026-10-12 09:00:00', 'ended_at' => '2026-10-12 10:15:30'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Záznam byl uložen');

    $entry->refresh();

    expect($entry->description)->toBe('Example after')
        ->and($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 07:00:00')
        ->and($entry->duration_seconds)->toBe(4530);
});

it('refuses an end before the start as a field error under Konec and leaves the row unchanged', function (): void {
    $entry = TimeEntry::factory()->create(['description' => 'Example kept']);
    $before = $entry->only(['started_at', 'ended_at']);

    $component = Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->fillForm(['description' => 'Example changed', 'started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 09:00:00'])
        ->call('save')
        ->assertHasFormErrors(['ended_at']);

    $entry->refresh();

    expect($component->errors()->get('data.ended_at'))->toContain('Konec musí být později než začátek.')
        ->and($entry->description)->toBe('Example kept')
        ->and($entry->started_at->equalTo($before['started_at']))->toBeTrue()
        ->and($entry->ended_at?->equalTo($before['ended_at']))->toBeTrue();
});

it('refuses an over-long description as a field error and leaves the row unchanged', function (): void {
    $entry = TimeEntry::factory()->create(['description' => 'Example kept']);

    Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->fillForm(['description' => str_repeat('x', 1001)])
        ->call('save')
        ->assertHasFormErrors(['description']);

    expect($entry->refresh()->description)->toBe('Example kept');
});

it('shows a running entry without Konec and with the badge, and keeps it running when a task is saved', function (): void {
    $task = entryFormTask();
    $entry = TimeEntry::factory()->running()->create(['client_id' => $task->project?->client_id]);

    Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->assertSee('Běží')
        ->assertFormFieldIsHidden('ended_at')
        ->set('data.task_id', $task->id)
        ->call('save')
        ->assertHasNoFormErrors();

    $entry->refresh();

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->project_id)->toBe($task->project_id)
        ->and($entry->ended_at)->toBeNull();
});

it('keeps a running entry editable after its task, project and client were archived', function (): void {
    $task = entryFormTask();
    $entry = TimeEntry::factory()->running()->create(['client_id' => $task->project?->client_id, 'project_id' => $task->project_id, 'task_id' => $task->id]);
    $task->delete();
    Project::query()->whereKey($task->project_id)->firstOrFail()->delete();
    Client::query()->whereKey($entry->client_id)->firstOrFail()->delete();

    Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->assertSee('Běží')
        ->fillForm(['description' => 'Example still saved'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($entry->refresh()->description)->toBe('Example still saved')
        ->and($entry->task_id)->toBe($task->id);
});

it('shows the duration live from the two pickers', function (): void {
    $client = Client::factory()->create();

    Livewire::test(CreateTimeEntry::class)
        ->fillForm(['client_id' => $client->id, 'started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 11:25:07'])
        ->assertSee('1:25:07')
        ->fillForm(['ended_at' => '2026-10-12 09:00:00'])
        ->assertDontSee('1:25:07');
});

it('warns about an overlap with another entry and still saves', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    $other = TimeEntry::factory()->create([
        'user_id' => $this->admin->id,
        'client_id' => $client->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'Europe/Prague')->utc(),
        'ended_at' => CarbonImmutable::parse('2026-10-12 10:00:00', 'Europe/Prague')->utc(),
    ]);

    Livewire::test(CreateTimeEntry::class)
        ->fillForm(['client_id' => $client->id, 'started_at' => '2026-10-12 09:30:00', 'ended_at' => '2026-10-12 10:30:00'])
        ->assertSee('Tento čas se překrývá s jiným záznamem (Cihla, 09:00–10:00). Uložit ho můžete i tak.')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TimeEntry::query()->count())->toBe(2)
        ->and($other->refresh()->id)->not->toBeNull();
});

it('shows no overlap warning for entries that only touch, for another user or for the entry being edited', function (): void {
    $client = Client::factory()->create();
    $entry = TimeEntry::factory()->create([
        'user_id' => $this->admin->id,
        'client_id' => $client->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'Europe/Prague')->utc(),
        'ended_at' => CarbonImmutable::parse('2026-10-12 10:00:00', 'Europe/Prague')->utc(),
    ]);
    TimeEntry::factory()->create([
        'client_id' => $client->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 12:00:00', 'Europe/Prague')->utc(),
        'ended_at' => CarbonImmutable::parse('2026-10-12 13:00:00', 'Europe/Prague')->utc(),
    ]);

    Livewire::test(CreateTimeEntry::class)
        ->fillForm(['client_id' => $client->id, 'started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 11:00:00'])
        ->assertDontSee('Tento čas se překrývá')
        ->fillForm(['started_at' => '2026-10-12 12:15:00', 'ended_at' => '2026-10-12 12:45:00'])
        ->assertDontSee('Tento čas se překrývá');

    Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->assertDontSee('Tento čas se překrývá');
});

it('presets the billable toggle off for a non-billable task and says so', function (): void {
    $task = entryFormTask(billing: ['billing_type' => 'non_billable']);

    Livewire::test(CreateTimeEntry::class)
        ->assertSet('data.billable', true)
        ->assertDontSee('Předvyplněno podle úkolu. Můžete to změnit.')
        ->set('data.task_id', $task->id)
        ->assertSet('data.billable', false)
        ->assertSee('Předvyplněno podle úkolu. Můžete to změnit.');
});

it('puts the toggle back on when the non-billable task is cleared before the user touched it', function (): void {
    $task = entryFormTask(billing: ['billing_type' => 'non_billable']);

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $task->id)
        ->assertSet('data.billable', false)
        ->set('data.task_id', null)
        ->assertSet('data.billable', true);
});

it('keeps the toggle the user set when another task is chosen', function (): void {
    $first = entryFormTask(billing: ['billing_type' => 'non_billable']);
    $second = entryFormTask(billing: ['billing_type' => 'non_billable']);
    $hourly = entryFormTask();

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $first->id)
        ->assertSet('data.billable', false)
        ->set('data.billable', true)
        ->set('data.task_id', $second->id)
        ->assertSet('data.billable', true)
        ->set('data.task_id', $hourly->id)
        ->assertSet('data.billable', true);
});

it('keeps the toggle on for a task of a fixed-price project and stores the toggle', function (): void {
    $task = entryFormTask(['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $task->id)
        ->assertSet('data.billable', true)
        ->fillForm(['started_at' => '2026-10-12 10:00:00', 'ended_at' => '2026-10-12 10:30:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TimeEntry::query()->firstOrFail()->billable)->toBeTrue();

    $nonBillable = entryFormTask(billing: ['billing_type' => 'non_billable']);

    Livewire::test(CreateTimeEntry::class)
        ->set('data.task_id', $nonBillable->id)
        ->fillForm(['started_at' => '2026-10-13 10:00:00', 'ended_at' => '2026-10-13 10:30:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TimeEntry::query()->where('task_id', $nonBillable->id)->firstOrFail()->billable)->toBeFalse();
});

it('refuses a save from an edit page left open after the entry was billed and leaves it unchanged', function (): void {
    $entry = TimeEntry::factory()->create(['description' => 'Example billed']);

    $page = Livewire::test(EditTimeEntry::class, ['record' => $entry->id])
        ->fillForm(['description' => 'Example changed']);

    // The entry is billed while the form is open: the page authorizes again on every request.
    $entry->forceFill(['billing_state' => 'billed', 'billed_at' => now()])->save();

    $page->call('save')->assertForbidden();

    expect($entry->refresh()->description)->toBe('Example billed');
});
