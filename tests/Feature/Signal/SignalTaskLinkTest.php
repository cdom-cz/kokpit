<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Signal\Actions\AddSignalTask;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Queries\LinkableTasks;
use App\Domain\Signal\Support\SignalRules;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\StopTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TaskResource;
use App\Livewire\Signal\SignalDay;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * Linking a planner task to an unfinished Kokpit task through the type-ahead of the add field (P17-07,
 * D-01, D-03). The clock is frozen on Monday 12 Oct 2026 (Prague noon) like SignalScreensTest; every
 * name and key is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function sigLinkProject(string $key = 'ATL', string $name = 'Example Atlas', array $attributes = []): Project
{
    return Project::factory()->create(array_merge(['key' => $key, 'name' => $name], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function sigLinkTask(Project $project, string $title, array $attributes = []): Task
{
    return Task::factory()->create(array_merge(['project_id' => $project->id, 'title' => $title], $attributes));
}

/**
 * The references the type-ahead offers for a typed term, in the order offered.
 *
 * @return list<string>
 */
function sigLinkOffered(string $term): array
{
    return array_column(app(LinkableTasks::class)->search($term), 'reference');
}

it('picks an unfinished task from the type-ahead and stores the signal linked with the snapshot text', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    expect($task->reference)->toBe('ATL-1');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->set('newTitle', 'login')
        ->assertSee('Example login fix')
        ->call('pickTask', $task->id)
        ->assertSet('pickedTaskId', $task->id)
        ->assertSet('pickedTaskLabel', 'Example Atlas: Example login fix [ATL-1]')
        ->assertSet('newTitle', 'Example Atlas: Example login fix [ATL-1]')
        ->call('addTask')
        ->assertSet('newTitle', '')
        ->assertSet('pickedTaskId', null)
        ->assertSet('pickedTaskLabel', null)
        ->assertDispatched('signal-changed');

    $signal = SignalTask::query()->sole();

    expect($signal->title)->toBe('Example Atlas: Example login fix [ATL-1]')
        ->and($signal->task_id)->toBe($task->id)
        ->and($signal->category)->toBe(SignalCategory::Extra);
});

it('never offers a finished, archived or unreachable task, but offers one hidden from the board', function (): void {
    $project = sigLinkProject('ATL', 'Example Atlas');
    sigLinkTask($project, 'Example open work');
    sigLinkTask($project, 'Example hidden work', ['hidden_on_board' => true]);
    sigLinkTask($project, 'Example done work', ['status' => 'done', 'completed_at' => now()]);
    sigLinkTask($project, 'Example archived work')->delete();

    $archivedProject = sigLinkProject('ARP', 'Example Archived Project');
    sigLinkTask($archivedProject, 'Example orphan work');
    $archivedProject->delete();

    $archivedClient = sigLinkProject('ARC', 'Example Archived Client');
    sigLinkTask($archivedClient, 'Example stranded work');
    $archivedClient->client->delete();

    expect(sigLinkOffered('work'))->toBe(['ATL-1', 'ATL-2']);
});

it('matches on the task code, a word of the name, the project key and a word of the project name, ignoring case', function (): void {
    sigLinkTask(sigLinkProject('ATL', 'Example Atlas'), 'Example login fix');
    sigLinkTask(sigLinkProject('ZED', 'Example Zenith'), 'Example other thing');

    expect(sigLinkOffered('atl-1'))->toBe(['ATL-1'])
        ->and(sigLinkOffered('LOGIN'))->toBe(['ATL-1'])
        ->and(sigLinkOffered('zed'))->toBe(['ZED-1'])
        ->and(sigLinkOffered('zenith'))->toBe(['ZED-1'])
        ->and(sigLinkOffered('example'))->toBe(['ATL-1', 'ZED-1']);
});

it('offers nothing for fewer than two characters or for a term nothing matches', function (): void {
    sigLinkTask(sigLinkProject(), 'Example login fix');

    expect(sigLinkOffered('l'))->toBe([])
        ->and(sigLinkOffered('  l '))->toBe([])
        ->and(sigLinkOffered(''))->toBe([])
        ->and(sigLinkOffered('nothing like this'))->toBe([]);
});

it('treats a percent sign and an underscore as plain characters', function (): void {
    $project = sigLinkProject();
    sigLinkTask($project, 'Example 100% done');
    sigLinkTask($project, 'Example 100 percent');
    sigLinkTask($project, 'Example a_b');
    sigLinkTask($project, 'Example axb');

    expect(sigLinkOffered('100%'))->toBe(['ATL-1'])
        ->and(sigLinkOffered('a_b'))->toBe(['ATL-3'])
        ->and(sigLinkOffered('%%'))->toBe([]);
});

it('offers at most ten tasks in project-key then number order', function (): void {
    $late = sigLinkProject('BBB', 'Example Late');

    foreach (range(1, 12) as $i) {
        sigLinkTask($late, "Example bulk {$i}");
    }

    sigLinkTask(sigLinkProject('AAA', 'Example Early'), 'Example bulk first');

    expect(sigLinkOffered('bulk'))->toBe(['AAA-1', 'BBB-1', 'BBB-2', 'BBB-3', 'BBB-4', 'BBB-5', 'BBB-6', 'BBB-7', 'BBB-8', 'BBB-9']);
});

it('offers nothing while a task is picked', function (): void {
    $project = sigLinkProject();
    $task = sigLinkTask($project, 'Example login fix');
    sigLinkTask($project, 'Example login other');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->set('newTitle', 'login')
        ->assertSee('Example login other')
        ->call('pickTask', $task->id)
        ->assertDontSee('Example login other');
});

it('stores the pick-time label whatever the field says afterwards', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('pickTask', $task->id)
        ->set('newTitle', 'Forged text')
        ->call('addTask');

    expect(SignalTask::query()->sole()->title)->toBe('Example Atlas: Example login fix [ATL-1]');
});

it('refuses a forged pick-time label from the browser and writes nothing', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');
    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->call('pickTask', $task->id);

    expect(fn () => $component->set('pickedTaskLabel', 'Forged text'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('pickedTaskId', (string) $task->id))->toThrow(CannotUpdateLockedPropertyException::class);

    expect(SignalTask::query()->count())->toBe(0);
});

it('keeps the snapshot when the task is renamed between the pick and the save', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');
    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->call('pickTask', $task->id);

    app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example renamed fix']);

    $component->call('addTask');

    $signal = SignalTask::query()->sole();

    expect($signal->title)->toBe('Example Atlas: Example login fix [ATL-1]')
        ->and($signal->task_id)->toBe($task->id);
});

it('keeps the stored text and the link when the task is renamed, completed or archived afterwards', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->call('pickTask', $task->id)->call('addTask');

    app(UpdateTask::class)->handle($this->admin, $task, ['title' => 'Example renamed fix']);
    app(UpdateTask::class)->handle($this->admin, $task->refresh(), ['status' => 'done']);
    app(ArchiveTask::class)->handle($this->admin, $task->refresh());

    $signal = SignalTask::query()->sole();

    expect($signal->title)->toBe('Example Atlas: Example login fix [ATL-1]')
        ->and($signal->task_id)->toBe($task->id);
});

it('shortens the task name so that the code suffix survives a too long text', function (): void {
    $task = sigLinkTask(sigLinkProject(), str_repeat('x', 255));
    $label = LinkableTasks::label($task->load('project'));

    expect(mb_strlen($label))->toBeLessThanOrEqual(SignalRules::MAX_TITLE_LENGTH)
        ->and($label)->toStartWith('Example Atlas: xxx')
        ->and($label)->toEndWith('… [ATL-1]');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->call('pickTask', $task->id)->call('addTask');

    $stored = SignalTask::query()->sole()->title;

    expect(mb_strlen($stored))->toBeLessThanOrEqual(SignalRules::MAX_TITLE_LENGTH)
        ->and($stored)->toEndWith('[ATL-1]');
});

it('refuses a pick that is malformed, unknown, finished or archived and leaves no pick', function (): void {
    $project = sigLinkProject();
    $done = sigLinkTask($project, 'Example done work', ['status' => 'done', 'completed_at' => now()]);
    $archived = sigLinkTask($project, 'Example archived work');
    $archived->delete();

    foreach (['not-a-uuid', (string) Str::uuid7(), $done->id, $archived->id] as $id) {
        Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
            ->call('pickTask', $id)
            ->assertNotified(__('kokpit.signal.errors.task_unavailable'))
            ->assertSet('pickedTaskId', null)
            ->assertSet('pickedTaskLabel', null);
    }
});

it('refuses an unavailable task in the Action and writes nothing', function (): void {
    $done = sigLinkTask(sigLinkProject(), 'Example done work', ['status' => 'done', 'completed_at' => now()]);

    foreach (['not-a-uuid', (string) Str::uuid7(), $done->id] as $id) {
        expect(fn () => app(AddSignalTask::class)->handle($this->admin, 'Example snapshot', '2026-10-12', null, $id))
            ->toThrow(SignalRuleViolation::class, __('kokpit.signal.errors.task_unavailable'));
    }

    expect(SignalTask::query()->count())->toBe(0);
});

it('drops the link and keeps the text when the picked task became unavailable before the save', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');
    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->call('pickTask', $task->id);

    app(UpdateTask::class)->handle($this->admin, $task, ['status' => 'done']);

    $component->call('addTask')
        ->assertNotified(__('kokpit.signal.errors.task_unavailable'))
        ->assertSet('pickedTaskId', null)
        ->assertSet('pickedTaskLabel', null)
        ->assertSet('newTitle', 'Example Atlas: Example login fix [ATL-1]');

    expect(SignalTask::query()->count())->toBe(0);
});

it('clears the pick and the field with the cross and stores typed text unlinked', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('pickTask', $task->id)
        ->call('clearPick')
        ->assertSet('pickedTaskId', null)
        ->assertSet('pickedTaskLabel', null)
        ->assertSet('newTitle', '')
        ->set('newTitle', 'Example free text')
        ->call('addTask');

    $signal = SignalTask::query()->sole();

    expect($signal->title)->toBe('Example free text')->and($signal->task_id)->toBeNull();
});

it('keeps the link when the title of a linked signal is edited', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->call('pickTask', $task->id)->call('addTask');

    $signal = SignalTask::query()->sole();

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('startEdit', $signal->id)
        ->set('editTitle', 'Example retitled')
        ->call('saveEdit');

    $signal->refresh();

    expect($signal->title)->toBe('Example retitled')->and($signal->task_id)->toBe($task->id);
});

it('applies the limit rule of free text to a pick', function (): void {
    foreach (range(1, 3) as $i) {
        SignalTask::query()->create(['title' => "Example main {$i}", 'for_date' => '2026-10-13', 'category' => SignalCategory::Main, 'position' => $i]);
    }

    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-13'])
        ->call('pickTask', $task->id)
        ->set('newCategory', 'main')
        ->call('addTask')
        ->assertNotified(__('kokpit.signal.errors.limit_main', ['limit' => 3]))
        ->assertSet('pickedTaskId', $task->id);

    expect(SignalTask::query()->whereNotNull('task_id')->count())->toBe(0);
});

it('refuses a pick on a locked past day', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-09'])
        ->call('pickTask', $task->id)
        ->call('addTask')
        ->assertNotified(__('kokpit.signal.errors.day_locked'));

    expect(SignalTask::query()->count())->toBe(0);
});

it('renders the add field as an accessible combobox with the new placeholder', function (): void {
    $html = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->html();

    expect($html)->toContain('role="combobox"')
        ->and($html)->toContain('aria-controls="signal-suggestions-2026-10-12"')
        ->and($html)->toContain('aria-autocomplete="list"')
        ->and($html)->toContain(__('kokpit.signal.day.new_placeholder'))
        ->and($html)->not->toContain('role="listbox"')
        ->and($html)->not->toContain('readonly')
        ->and(substr_count($html, 'maxlength="255"'))->toBe(1);
});

it('renders a listbox with one option per suggestion once two characters match, and nothing for a miss', function (): void {
    $project = sigLinkProject();
    $login = sigLinkTask($project, 'Example login fix');
    sigLinkTask($project, 'Example login other');

    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->set('newTitle', 'login');
    $html = $component->html();

    expect($html)->toContain('role="listbox"')
        ->and($html)->toContain('id="signal-suggestions-2026-10-12"')
        ->and(substr_count($html, 'role="option"'))->toBe(2)
        ->and($html)->toContain("pickTask('{$login->id}')")
        ->and($html)->toContain('signal-suggestion-2026-10-12-1');

    $component->set('newTitle', 'l');
    expect($component->html())->not->toContain('role="listbox"');

    $component->set('newTitle', 'no match at all');
    expect($component->html())->not->toContain('role="listbox"')->not->toContain(__('kokpit.signal.errors.task_unavailable'));
});

it('makes the field read-only after a pick, shows the clear control and no listbox', function (): void {
    $task = sigLinkTask(sigLinkProject(), 'Example login fix');

    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->set('newTitle', 'login')
        ->call('pickTask', $task->id);
    $html = $component->html();

    expect($html)->toContain('readonly')
        ->and($html)->toContain(__('kokpit.signal.day.clear_pick'))
        ->and($html)->toContain('clearPick')
        ->and($html)->not->toContain('role="listbox"');

    $component->call('clearPick');

    expect($component->html())->not->toContain('readonly')->not->toContain('clearPick');
});

/*
 * The listing of a linked planner task (P17-07, D-01, D-02, D-03): the shared task timer and the icon that
 * opens the task. The task title of the row stays plain text.
 */

/**
 * A task of a fresh hourly project that a timer can be started on, written through the domain Actions.
 */
function sigLinkTimed(string $title = 'Example timed work'): Task
{
    $project = app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example timer project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
    ]);

    return app(CreateTask::class)->handle(test()->admin, $project, ['title' => $title]);
}

/**
 * A signal of the day linked to the task, written the way AddSignalTask does it (the link is never mass-assigned).
 *
 * @param  array<string, mixed>  $state
 */
function sigLinkSignal(Task $task, string $date = '2026-10-12', array $state = []): SignalTask
{
    $signal = SignalTask::query()->create([
        'title' => 'Example snapshot ['.$task->reference.']',
        'for_date' => $date,
        'category' => SignalCategory::Extra,
        'position' => 0,
    ]);

    $signal->forceFill(['task_id' => $task->id, ...$state])->save();

    return $signal;
}

/**
 * The markup of one planner row of the rendered day.
 */
function sigLinkRow(string $html, SignalTask $signal): string
{
    preg_match('/<li\b[^>]*wire:key="task-'.preg_quote($signal->id, '/').'"[^>]*>.*?<\/li>/s', $html, $match);

    return html_entity_decode($match[0] ?? '');
}

it('offers the task page and a play button on a linked row, never a link on the text', function (): void {
    $task = sigLinkTimed();
    $signal = sigLinkSignal($task);
    $plain = SignalTask::query()->create(['title' => 'Example plain text', 'for_date' => '2026-10-12', 'category' => SignalCategory::Extra, 'position' => 1]);

    $html = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->html();
    $row = sigLinkRow($html, $signal);

    preg_match('/<a\b[^>]*>(.*?)<\/a>/s', $row, $anchor);

    expect($row)->toContain('href="'.TaskResource::getUrl('view', ['record' => $task]).'"')
        ->and($row)->toContain(__('kokpit.task_board.card.open'))
        ->and($row)->toContain("toggleTaskTimer('{$signal->id}')")
        ->and($row)->toContain(__('kokpit.time.timer.start'))
        ->and($row)->toContain('aria-pressed="false"')
        ->and($row)->not->toContain(__('kokpit.time.timer.stop'))
        ->and($anchor[1] ?? '')->not->toContain('Example snapshot')
        ->and(preg_match_all('/<a[\s>]/', $row))->toBe(1);

    $plainRow = sigLinkRow($html, $plain);

    expect($plainRow)->not->toContain('toggleTaskTimer')
        ->and($plainRow)->not->toContain(__('kokpit.task_board.card.open'))
        ->and($plainRow)->not->toContain('<a ');
});

it('starts and stops the shared timer of the linked task from the row', function (): void {
    $task = sigLinkTimed();
    $signal = sigLinkSignal($task);

    $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('toggleTaskTimer', $signal->id)
        ->assertDispatched('timer-started')
        ->assertNotified(__('kokpit.time.timer.started'));

    $entry = TimeEntry::query()->whereNull('ended_at')->sole();

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->user_id)->toBe($this->admin->id);

    $row = sigLinkRow($component->html(), $signal);

    expect($row)->toContain(__('kokpit.time.timer.stop'))
        ->and($row)->toContain('aria-pressed="true"')
        ->and($row)->not->toContain(__('kokpit.time.timer.start'));

    $component->call('toggleTaskTimer', $signal->id)
        ->assertDispatched('timer-stopped')
        ->assertNotified(__('kokpit.time.timer.stopped'));

    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(sigLinkRow($component->html(), $signal))->toContain(__('kokpit.time.timer.start'));
});

it('hides the timer but keeps the open-task icon for a completed or archived task and shows neither for a deleted one', function (): void {
    $done = sigLinkTimed('Example finished work');
    $archived = sigLinkTimed('Example archived work');
    $deleted = sigLinkTimed('Example deleted work');
    $doneSignal = sigLinkSignal($done);
    $archivedSignal = sigLinkSignal($archived);
    $deletedSignal = sigLinkSignal($deleted);

    app(UpdateTask::class)->handle($this->admin, $done, ['status' => 'done']);
    app(ArchiveTask::class)->handle($this->admin, $archived);
    $deleted->forceDelete();

    $html = Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->html();

    foreach ([[$doneSignal, $done], [$archivedSignal, $archived]] as [$signal, $task]) {
        $row = sigLinkRow($html, $signal);

        expect($row)->toContain('href="'.TaskResource::getUrl('view', ['record' => $task]).'"')
            ->and($row)->toContain(__('kokpit.task_board.card.open'))
            ->and($row)->not->toContain('toggleTaskTimer')
            ->and($row)->not->toContain(__('kokpit.time.timer.start'));
    }

    $deletedRow = sigLinkRow($html, $deletedSignal);

    expect($deletedSignal->refresh()->task_id)->toBeNull()
        ->and($deletedRow)->not->toContain('toggleTaskTimer')
        ->and($deletedRow)->not->toContain(__('kokpit.task_board.card.open'))
        ->and($deletedRow)->not->toContain('<a ');
});

it('shows both controls on a locked past day and on a done signal, while edit and delete stay absent on the locked day', function (): void {
    $lockedSignal = sigLinkSignal(sigLinkTimed('Example past work'), '2026-10-09');
    $doneSignal = sigLinkSignal(sigLinkTimed('Example ticked work'), '2026-10-12', ['is_done' => true, 'completed_at' => now()]);

    $locked = sigLinkRow(Livewire::test(SignalDay::class, ['forDate' => '2026-10-09'])->html(), $lockedSignal);

    expect($locked)->toContain('toggleTaskTimer')
        ->and($locked)->toContain(__('kokpit.task_board.card.open'))
        ->and($locked)->not->toContain(__('kokpit.signal.day.edit'))
        ->and($locked)->not->toContain(__('kokpit.signal.day.delete'));

    $done = sigLinkRow(Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])->html(), $doneSignal);

    expect($done)->toContain('toggleTaskTimer')
        ->and($done)->toContain(__('kokpit.task_board.card.open'))
        ->and($done)->toContain('line-through')
        ->and($done)->toContain('style="display: flex; flex: 0 0 auto; gap: 0.25rem;"');
});

it('lets the row start the timer on the locked past day', function (): void {
    $task = sigLinkTimed();
    $signal = sigLinkSignal($task, '2026-10-09');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-09'])
        ->call('toggleTaskTimer', $signal->id)
        ->assertDispatched('timer-started');

    expect(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($task->id);
});

it('stops and keeps the timer running elsewhere with the same toast as the timer bar', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $other = sigLinkTimed('Example other work');
    $old = TimeEntry::factory()->forTask($other)->create([
        'user_id' => $this->admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 06:30:00', 'UTC'),
        'ended_at' => null,
    ]);
    $task = sigLinkTimed();
    $signal = sigLinkSignal($task);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('toggleTaskTimer', $signal->id)
        ->assertDispatched('timer-started')
        ->assertNotified(
            Notification::make()
                ->success()
                ->title(__('kokpit.time.timer.started'))
                ->body('Předchozí záznam (1:30) byl zastaven a uložen.'),
        );

    $this->travelBack();

    expect($old->refresh()->ended_at)->not->toBeNull()
        ->and($old->duration_seconds)->toBe(5400)
        ->and(TimeEntry::query()->whereNull('ended_at')->sole()->task_id)->toBe($task->id);
});

it('refuses a start on a completed or archived task from a stale row and starts nothing', function (): void {
    $done = sigLinkTimed('Example finished work');
    $archived = sigLinkTimed('Example archived work');
    $doneSignal = sigLinkSignal($done);
    $archivedSignal = sigLinkSignal($archived);

    app(UpdateTask::class)->handle($this->admin, $done, ['status' => 'done']);
    app(ArchiveTask::class)->handle($this->admin, $archived);

    foreach ([$doneSignal, $archivedSignal] as $signal) {
        Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
            ->call('toggleTaskTimer', $signal->id)
            ->assertNotified(__('kokpit.signal.errors.task_unavailable'))
            ->assertNotDispatched('timer-started');
    }

    expect(TimeEntry::query()->count())->toBe(0);
});

it('stops the running timer of a task that was completed meanwhile', function (): void {
    $task = sigLinkTimed();
    $signal = sigLinkSignal($task);

    app(UpdateTask::class)->handle($this->admin, $task, ['status' => 'done']);
    TimeEntry::factory()->forTask($task)->running()->create(['user_id' => $this->admin->id]);

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('toggleTaskTimer', $signal->id)
        ->assertDispatched('timer-stopped');

    expect(TimeEntry::query()->whereNull('ended_at')->count())->toBe(0);
});

it('re-reads the day on every timer event so that the cards and the top bar agree', function (): void {
    foreach (['timer-started', 'timer-stopped', 'time-entry-saved', 'time-entry-deleted'] as $event) {
        $task = sigLinkTimed("Example {$event} work");
        $signal = sigLinkSignal($task, '2026-10-13');
        $component = Livewire::test(SignalDay::class, ['forDate' => '2026-10-13']);

        expect(sigLinkRow($component->html(), $signal))->toContain(__('kokpit.time.timer.start'));

        TimeEntry::factory()->forTask($task)->running()->create(['user_id' => $this->admin->id]);
        $component->dispatch($event);

        expect(sigLinkRow($component->html(), $signal))->toContain(__('kokpit.time.timer.stop'))
            ->and(sigLinkRow($component->html(), $signal))->toContain('aria-pressed="true"');

        app(StopTimer::class)->handle($this->admin);
        $component->dispatch($event);

        expect(sigLinkRow($component->html(), $signal))->toContain(__('kokpit.time.timer.start'));
    }
});

it('loads the linked tasks of a day in one query however many are linked', function (): void {
    $queries = static function (int $linked): int {
        $date = $linked === 1 ? '2026-10-13' : '2026-10-14';
        $prefix = $linked === 1 ? 'QA' : 'QB';

        foreach (range(1, $linked) as $i) {
            sigLinkSignal(sigLinkTask(sigLinkProject($prefix.chr(64 + $i), "Example Query {$prefix} {$i}"), "Example query work {$i}"), $date);
        }

        $component = Livewire::test(SignalDay::class, ['forDate' => $date]);
        $component->call('timerChanged');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('timerChanged');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queries(10))->toBe($queries(1));
});

it('refuses a forged or unknown signal id and starts nothing', function (): void {
    $plain = SignalTask::query()->create(['title' => 'Example plain text', 'for_date' => '2026-10-12', 'category' => SignalCategory::Extra, 'position' => 0]);

    foreach (['not-a-uuid', (string) Str::uuid7(), $plain->id] as $id) {
        Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
            ->call('toggleTaskTimer', $id)
            ->assertNotified(__('kokpit.signal.errors.not_found'))
            ->assertNotDispatched('timer-started');
    }

    expect(TimeEntry::query()->count())->toBe(0);
});

it('does not start a timer through a row of another day', function (): void {
    $signal = sigLinkSignal(sigLinkTimed(), '2026-10-13');

    Livewire::test(SignalDay::class, ['forDate' => '2026-10-12'])
        ->call('toggleTaskTimer', $signal->id)
        ->assertNotified(__('kokpit.signal.errors.not_found'));

    expect(TimeEntry::query()->count())->toBe(0);
});
