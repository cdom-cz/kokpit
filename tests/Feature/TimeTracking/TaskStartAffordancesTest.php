<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\TaskBoardPage;
use App\Filament\Resources\ProjectResource\Pages\ProjectBoard;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TimeEntryResource;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The start and stop affordances on the task page, the task list and the board cards (TI-01, D-01,
 * D-02, D-03), the "Čas" section of the task page and the absence of all of it for a Partner.
 * Every name is fictional.
 */

/**
 * A task of a fresh hourly project, written through the domain Actions.
 *
 * @param  array<string, mixed>  $changes  billing keys applied through UpdateTask like on the edit page
 */
function taskStartTask(array $changes = [], ?Project $project = null): Task
{
    $project ??= app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example start project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
    ]);

    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example start task']);

    return $changes === [] ? $task : app(UpdateTask::class)->handle(test()->admin, $task, $changes)->refresh();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('starts a timer for the task from its page in one click with the context of the task', function (): void {
    $task = taskStartTask();

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionVisible('toggleTimer')
        ->assertActionHasLabel('toggleTimer', 'Spustit časovač')
        ->assertActionHasColor('toggleTimer', 'gray')
        ->callAction('toggleTimer')
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn');

    $entry = TimeEntry::query()->whereNull('ended_at')->sole();

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->project_id)->toBe($task->project_id)
        ->and($entry->client_id)->toBe(Project::query()->findOrFail($task->project_id)->client_id)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->billable)->toBeTrue()
        ->and($entry->description)->toBeNull();
});

it('stores a non-billable entry when the task is non-billable', function (): void {
    $task = taskStartTask(['billing_type' => 'non_billable']);

    Livewire::test(ViewTask::class, ['record' => $task->reference])->callAction('toggleTimer');

    expect(TimeEntry::query()->whereNull('ended_at')->sole()->billable)->toBeFalse();
});

it('stops the running timer of another task, keeps it and names its duration in the toast', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $other = taskStartTask();
    $old = TimeEntry::factory()->forTask($other)->create([
        'user_id' => $this->admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 06:30:00', 'UTC'),
        'ended_at' => null,
    ]);
    $task = taskStartTask();

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->callAction('toggleTimer')
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn');

    $this->travelBack();

    expect($old->refresh()->ended_at)->not->toBeNull()
        ->and($old->duration_seconds)->toBe(5400)
        ->and(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($task->id);
});

it('says in the toast which previous record was stopped', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    TimeEntry::factory()->forTask(taskStartTask())->create([
        'user_id' => $this->admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 06:30:00', 'UTC'),
        'ended_at' => null,
    ]);
    $task = taskStartTask();

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->callAction('toggleTimer')
        ->assertNotified(
            Notification::make()
                ->success()
                ->title('Časovač byl spuštěn')
                ->body('Předchozí záznam (1:30) byl zastaven a uložen.'),
        );
});

it('offers the stop in the same slot while the task is the running one and stops it', function (): void {
    $task = taskStartTask();
    TimeEntry::factory()->forTask($task)->running()->create(['user_id' => $this->admin->id]);

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionHasLabel('toggleTimer', 'Zastavit časovač')
        ->assertActionHasColor('toggleTimer', 'warning')
        ->assertActionHasIcon('toggleTimer', Heroicon::OutlinedStop)
        ->callAction('toggleTimer')
        ->assertDispatched('timer-stopped')
        ->assertNotified('Časovač byl zastaven');

    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(0);
});

it('offers the same one-click toggle on the edit page', function (): void {
    $task = taskStartTask();

    Livewire::test(EditTask::class, ['record' => $task->reference])
        ->assertActionHasLabel('toggleTimer', 'Spustit časovač')
        ->callAction('toggleTimer')
        ->assertNotified('Časovač byl spuštěn')
        ->assertActionHasLabel('toggleTimer', 'Zastavit časovač');

    expect(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($task->id);
});

it('offers no timer action on the page of an archived task', function (): void {
    $task = taskStartTask();
    app(ArchiveTask::class)->handle($this->admin, $task);

    Livewire::test(ViewTask::class, ['record' => $task->reference])->assertActionHidden('toggleTimer');

    expect(TimeEntry::query()->count())->toBe(0);
});

/**
 * The tasks listed by the Admin task list, in one project.
 *
 * @return list<Task>
 */
function taskStartListTasks(int $count): array
{
    $project = app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example list project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
    ]);

    return array_values(Task::factory()->count($count)->create(['project_id' => $project->id])->all());
}

it('starts the timer from a row of the task list with one click and turns the row into the stop', function (): void {
    [$first, $second] = taskStartListTasks(2);

    Livewire::test(ListTasks::class)
        ->assertTableActionVisible('toggleTimer', $first)
        ->assertTableActionHasLabel('toggleTimer', 'Spustit časovač', $first)
        ->callTableAction('toggleTimer', $first)
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn')
        ->assertTableActionHasIcon('toggleTimer', Heroicon::OutlinedStop, $first)
        ->assertTableActionHasLabel('toggleTimer', 'Zastavit časovač', $first)
        ->assertTableActionHasIcon('toggleTimer', Heroicon::OutlinedPlay, $second);

    expect(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($first->id);

    Livewire::test(ListTasks::class)->callTableAction('toggleTimer', $first)->assertDispatched('timer-stopped');

    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(0);
});

it('offers the list timer as an icon button with a tooltip outside any action group', function (): void {
    taskStartListTasks(1);

    $html = (string) Livewire::test(ListTasks::class)->html();
    preg_match('/<button[^>]*toggleTimer[^>]*>/s', $html, $button);

    expect($button)->not->toBeEmpty()
        ->and($button[0])->toContain('fi-icon-btn')
        ->and($button[0])->toContain("content: 'Spustit časovač'")
        ->and($button[0])->toContain('aria-label="Spustit časovač"')
        ->and($html)->not->toContain('fi-dropdown-list-item');
});

it('has no list timer on an archived row', function (): void {
    [$task] = taskStartListTasks(1);
    app(ArchiveTask::class)->handle($this->admin, $task);

    Livewire::test(ListTasks::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$task])
        ->assertTableActionHidden('toggleTimer', $task);
});

it('reads the running task once for twenty rows', function (): void {
    taskStartListTasks(20);
    TimeEntry::factory()->running()->create(['user_id' => $this->admin->id]);

    DB::enableQueryLog();
    Livewire::test(ListTasks::class)->assertSuccessful();
    $runningReads = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains($query['query'], 'from "time_entries"') && str_contains($query['query'], '"ended_at" is null'))
        ->count();
    DB::disableQueryLog();

    expect($runningReads)->toBe(1);
});

/**
 * One task on the global board, in a project of its own.
 */
function taskStartBoardTask(): Task
{
    return taskStartListTasks(1)[0];
}

/**
 * The html of the board with the markup of one card cut out.
 */
function taskStartCardHtml(string $html, Task $task): string
{
    preg_match('/<article[^>]*wire:sort:item="'.preg_quote($task->id, '/').'".*?<\/article>/s', $html, $match);

    return $match[0] ?? '';
}

it('shows a start button on every card of the global board and of a project board inside the sort-ignore wrapper', function (): void {
    $task = taskStartBoardTask();

    $global = taskStartCardHtml((string) Livewire::test(TaskBoardPage::class)->html(), $task);
    $project = taskStartCardHtml((string) Livewire::test(ProjectBoard::class, ['record' => $task->project_id])->html(), $task);

    foreach ([$global, $project] as $card) {
        expect($card)->toContain("toggleTimer('{$task->id}')")
            ->and($card)->toContain('aria-label="Spustit časovač"')
            ->and(preg_match('/wire:sort:ignore.*toggleTimer/s', $card))->toBe(1)
            ->and($card)->toContain('type="button"');
    }

    // Never a link: the button is not inside an anchor.
    expect(preg_match('/<a[^>]*>[^<]*<button[^>]*toggleTimer/s', $global))->toBe(0);
});

it('makes the card button at least 1.5rem square with half a rem to its neighbours', function (): void {
    $task = taskStartBoardTask();

    $card = taskStartCardHtml((string) Livewire::test(TaskBoardPage::class)->html(), $task);
    preg_match('/<button[^>]*toggleTimer[^>]*>/s', $card, $button);

    expect($button[0])->toContain('width: 1.5rem')->toContain('height: 1.5rem')
        ->and($card)->toContain('wire:sort:ignore style="display: flex; align-items: center; gap: 0.5rem;"');
});

it('starts and stops the timer from a board card and re-renders the card', function (): void {
    $task = taskStartBoardTask();

    $component = Livewire::test(TaskBoardPage::class)
        ->call('toggleTimer', $task->id)
        ->assertDispatched('timer-started')
        ->assertNotified('Časovač byl spuštěn');

    expect(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($task->id)
        ->and(taskStartCardHtml((string) $component->html(), $task))->toContain('aria-label="Zastavit časovač"');

    $component->call('toggleTimer', $task->id)->assertDispatched('timer-stopped')->assertNotified('Časovač byl zastaven');

    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(taskStartCardHtml((string) $component->html(), $task))->toContain('aria-label="Spustit časovač"');
});

it('stops a running timer of another task when a card starts one and says so', function (): void {
    [$first, $second] = taskStartListTasks(2);
    $old = TimeEntry::factory()->forTask($first)->running()->create(['user_id' => $this->admin->id]);

    Livewire::test(ProjectBoard::class, ['record' => $second->project_id])
        ->call('toggleTimer', $second->id)
        ->assertNotified('Časovač byl spuštěn');

    expect($old->refresh()->ended_at)->not->toBeNull()
        ->and(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($second->id);
});

it('answers 404 to a malformed or unknown card id and writes nothing', function (string $id): void {
    taskStartBoardTask();

    Livewire::test(TaskBoardPage::class)->call('toggleTimer', $id)->assertNotFound();

    expect(TimeEntry::query()->count())->toBe(0);
})->with(['malformed' => 'not-an-id', 'unknown' => '01a11da2-6a80-7239-b49e-fc913bf8e2bb']);

it('refuses to start a timer on a card whose task was archived meanwhile and tells why', function (): void {
    $task = taskStartBoardTask();
    $component = Livewire::test(TaskBoardPage::class);
    app(ArchiveTask::class)->handle($this->admin, $task);

    $component->call('toggleTimer', $task->id)->assertNotified('Úkol je archivovaný. Vyberte jiný úkol.');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('still stops the timer of a task that was archived meanwhile', function (): void {
    $task = taskStartBoardTask();
    $entry = TimeEntry::factory()->forTask($task)->running()->create(['user_id' => $this->admin->id]);
    $component = Livewire::test(TaskBoardPage::class);
    app(ArchiveTask::class)->handle($this->admin, $task);

    $component->call('toggleTimer', $task->id)->assertDispatched('timer-stopped');

    expect($entry->refresh()->ended_at)->not->toBeNull();
});

it('refuses a forged card toggle by a Partner on a board the Admin mounted and writes nothing', function (): void {
    $task = taskStartBoardTask();
    $board = Livewire::test(TaskBoardPage::class);

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    $board->call('toggleTimer', $task->id)->assertForbidden();

    expect(TimeEntry::query()->count())->toBe(0);
});

it('shows the worked and the unbilled time of the task with a running entry at its elapsed time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
    $task = taskStartTask();
    $at = static fn (string $time): CarbonImmutable => CarbonImmutable::parse('2026-10-12 '.$time, 'UTC');

    TimeEntry::factory()->forTask($task)->create(['user_id' => test()->admin->id, 'started_at' => $at('06:00:00'), 'ended_at' => $at('07:30:00')]);
    TimeEntry::factory()->forTask($task)->billed()->create(['user_id' => test()->admin->id, 'started_at' => $at('08:00:00'), 'ended_at' => $at('08:45:00')]);
    TimeEntry::factory()->forTask($task)->create(['user_id' => test()->admin->id, 'billable' => false, 'started_at' => $at('09:00:00'), 'ended_at' => $at('09:20:00')]);
    TimeEntry::factory()->forTask($task)->create(['user_id' => test()->admin->id, 'started_at' => $at('11:50:00'), 'ended_at' => null]);
    // Another task of the same project carries its own time and is not counted here.
    TimeEntry::factory()->forTask(taskStartTask(project: Project::query()->findOrFail($task->project_id)))->create(['user_id' => test()->admin->id, 'started_at' => $at('05:00:00'), 'ended_at' => $at('05:50:00')]);

    $html = (string) Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertSee('Odpracováno')
        ->assertSee('Nevyfakturováno')
        ->html();

    expect(preg_replace('/\s+/', ' ', strip_tags($html)))
        ->toContain('Odpracováno 2:45')
        ->toContain('Nevyfakturováno 1:40');
});

it('shows zero time for a task without entries', function (): void {
    $task = taskStartTask();

    $html = (string) Livewire::test(ViewTask::class, ['record' => $task->reference])->html();

    expect(preg_replace('/\s+/', ' ', strip_tags($html)))
        ->toContain('Odpracováno 0:00')
        ->toContain('Nevyfakturováno 0:00');
});

it('reads the time of the task with one aggregate query and shows no money', function (): void {
    $task = taskStartTask();
    TimeEntry::factory()->forTask($task)->create(['user_id' => $this->admin->id]);

    DB::enableQueryLog();
    $html = (string) Livewire::test(ViewTask::class, ['record' => $task->reference])->html();
    $sums = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'FILTER'))->count();
    DB::disableQueryLog();

    $section = substr($html, (int) strpos($html, 'Odpracováno'));

    expect($sums)->toBe(1)
        ->and(substr($section, 0, 1500))->not->toContain('Kč');
});

it('links to the entries list filtered to the task and that list shows only its entries', function (): void {
    $task = taskStartTask();
    $own = TimeEntry::factory()->forTask($task)->create(['user_id' => $this->admin->id]);
    $foreign = TimeEntry::factory()->forTask(taskStartTask())->create(['user_id' => $this->admin->id]);
    $url = TimeEntryResource::getUrl('index', ['filters' => ['task_id' => ['value' => $task->id]]]);

    $html = (string) Livewire::test(ViewTask::class, ['record' => $task->reference])->assertSee('Zobrazit záznamy')->html();

    expect(html_entity_decode($html))->toContain($url);

    Livewire::withQueryParams(['filters' => ['task_id' => ['value' => $task->id]]])
        ->test(ListTimeEntries::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

/**
 * A task of a client-visible project of the client, written by the Admin.
 */
function taskStartPartnerTask(string $clientId): Task
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($clientId): Task {
        $project = app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
            'name' => 'Example partner project',
            'key' => ProjectFactory::randomKey(),
            'billing_type' => 'hourly',
            'client_visible' => true,
        ]);

        return app(CreateTask::class)->handle(test()->admin, $project, ['title' => 'Example partner task']);
    });
}

it('shows a Partner none of the timer controls or time figures on the task list and the task page', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $task = taskStartPartnerTask((string) $partner->client_id);
    TimeEntry::factory()->forTask($task)->create(['user_id' => $this->admin->id]);

    $this->actingAs($partner);

    foreach (['/admin/my-tasks', '/admin/my-tasks/'.$task->reference] as $url) {
        $body = (string) $this->get($url)->assertOk()->getContent();

        expect($body)->toContain('Example partner task');

        foreach (['Spustit časovač', 'Zastavit časovač', 'Odpracováno', 'Nevyfakturováno', 'toggleTimer'] as $text) {
            expect($body)->not->toContain($text, $url);
        }
    }
});

it('refuses a Partner the Admin task page and the board toggle', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $task = taskStartPartnerTask((string) $partner->client_id);

    $this->actingAs($partner);

    $this->get('/admin/tasks/'.$task->reference)->assertForbidden();
    Livewire::test(ViewTask::class, ['record' => $task->reference])->assertForbidden();
    Livewire::test(TaskBoardPage::class)->assertForbidden();
});

it('shows no timer control in the preview slide-over of a board card', function (): void {
    $task = taskStartBoardTask();

    $component = Livewire::test(TaskBoardPage::class)->mountAction('preview', ['task' => $task->id]);
    $action = $component->instance()->getMountedAction();
    $html = $action->getModalHeading().' '.$action->getModalContent()?->render().' '.implode(' ', array_map(
        static fn ($extra): string => (string) $extra->getLabel(),
        array_values($action->getExtraModalFooterActions()),
    ));

    expect($html)->toContain($task->reference)
        ->not->toContain('časovač')
        ->not->toContain('toggleTimer')
        ->not->toContain('Odpracováno');
});
