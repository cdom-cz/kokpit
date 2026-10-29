<?php

declare(strict_types=1);

use App\Domain\Projects\Models\Project;
use App\Domain\Signal\Actions\AddSignalTask;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Queries\LinkableTasks;
use App\Domain\Signal\Support\SignalRules;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Livewire\Signal\SignalDay;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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
