<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Support\TaskDueDate;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The overdue highlight on the Admin task list (TA-05, RE-04): an open task (not done, not archived) whose due day
 * is before today in Prague shows the danger date, the "Po termínu" badge and the line "Po termínu o N ...", the
 * same cell as the "Nejbližší termíny" widget. Time is frozen at 2026-10-14 08:00 UTC (10:00 in Prague). Every
 * name is fictional.
 */

/**
 * The plain text of a rendered page: no svg, comments or scripts, no tags, one space between words.
 */
function taskOverdueText(string $html): string
{
    $html = (string) preg_replace(['/<svg.*?<\/svg>/s', '/<!--.*?-->/s', '/<script.*?<\/script>/s'], '', $html);

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
}

/**
 * A project written through the domain Action.
 */
function taskOverdueProject(): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example overdue project',
        'key' => 'OVD',
        'billing_type' => 'hourly',
    ]);
}

/**
 * A task of the project with the given attributes (an open status unless the caller says otherwise).
 *
 * @param  array<string, mixed>  $attributes
 */
function taskOverdueTask(Project $project, array $attributes = []): Task
{
    return Task::factory()->create(['project_id' => $project->id, 'status' => ProjectStatus::InProgress, ...$attributes]);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->project = taskOverdueProject();
});

it('highlights the open tasks that are overdue and leaves the others plain', function (): void {
    foreach (['2026-10-13', '2026-10-11', '2026-10-09', '2026-10-14', '2026-10-16'] as $due) {
        taskOverdueTask($this->project, ['due_date' => $due]);
    }

    $html = Livewire::test(ListTasks::class)->html();
    $text = taskOverdueText($html);

    expect($text)
        ->toContain('Po termínu o 1 den')
        ->toContain('Po termínu o 3 dny')
        ->toContain('Po termínu o 5 dní')
        ->toContain('Dnes')
        ->toContain('16. 10. 2026')
        ->and(substr_count($text, 'Po termínu o'))->toBe(3)
        ->and(substr_count($text, 'Po termínu'))->toBe(6)
        ->and(substr_count($html, 'var(--danger-600)'))->toBe(6);
});

it('gives no alarm to an open task due today or later', function (): void {
    taskOverdueTask($this->project, ['due_date' => '2026-10-14']);
    taskOverdueTask($this->project, ['due_date' => '2026-10-20']);

    $html = Livewire::test(ListTasks::class)->html();

    expect(taskOverdueText($html))->not->toContain('Po termínu')
        ->and($html)->not->toContain('var(--danger-600)');
});

it('gives no alarm to a done task whose due day has passed', function (): void {
    taskOverdueTask($this->project, ['status' => ProjectStatus::Done, 'due_date' => '2026-10-01']);

    $html = Livewire::test(ListTasks::class)->filterTable('status', ['done'])->html();

    expect(taskOverdueText($html))->toContain('1. 10. 2026')->not->toContain('Po termínu')
        ->and($html)->not->toContain('var(--danger-600)');
});

it('gives no alarm to an archived task whose due day has passed', function (): void {
    taskOverdueTask($this->project, ['due_date' => '2026-10-01'])->delete();

    $html = Livewire::test(ListTasks::class)->filterTable('trashed', false)->html();

    expect(taskOverdueText($html))->toContain('1. 10. 2026')->not->toContain('Po termínu')
        ->and($html)->not->toContain('var(--danger-600)');
});

it('shows the empty value for a task without a due date', function (): void {
    $task = taskOverdueTask($this->project, ['due_date' => null]);

    $html = Livewire::test(ListTasks::class)->assertCanSeeTableRecords([$task])->html();

    expect($html)->toContain('fi-ta-placeholder')
        ->and(taskOverdueText($html))->not->toContain('Po termínu')
        ->and($html)->not->toContain('var(--danger-600)');
});

it('draws the day boundary at midnight in Prague', function (): void {
    taskOverdueTask($this->project, ['due_date' => '2026-10-14']);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC'));
    $after = taskOverdueText(Livewire::test(ListTasks::class)->html());

    $this->travelTo(CarbonImmutable::parse('2026-10-14 21:30:00', 'UTC'));
    $before = taskOverdueText(Livewire::test(ListTasks::class)->html());

    expect($after)->toContain('Po termínu o 1 den')
        ->and($before)->toContain('Dnes')->not->toContain('Po termínu');
});

it('sorts by the due date in both directions', function (): void {
    $late = taskOverdueTask($this->project, ['due_date' => '2026-10-20']);
    $early = taskOverdueTask($this->project, ['due_date' => '2026-10-02']);
    $middle = taskOverdueTask($this->project, ['due_date' => '2026-10-10']);

    Livewire::test(ListTasks::class)
        ->sortTable('due_date')
        ->assertCanSeeTableRecords([$early, $middle, $late], inOrder: true)
        ->sortTable('due_date', 'desc')
        ->assertCanSeeTableRecords([$late, $middle, $early], inOrder: true);
});

it('counts the overdue days only for an open, live task with a due day before today', function (): void {
    $today = '2026-10-14';

    $open = taskOverdueTask($this->project, ['due_date' => '2026-10-09']);
    $undated = taskOverdueTask($this->project, ['due_date' => null]);
    $done = taskOverdueTask($this->project, ['status' => ProjectStatus::Done, 'due_date' => '2026-10-09']);
    $archived = taskOverdueTask($this->project, ['due_date' => '2026-10-09']);
    $archived->delete();
    $dueToday = taskOverdueTask($this->project, ['due_date' => $today]);
    $later = taskOverdueTask($this->project, ['due_date' => '2026-10-20']);

    expect(TaskDueDate::overdueDaysOf($open, $today))->toBe(5)
        ->and(TaskDueDate::overdueDaysOf($undated, $today))->toBe(0)
        ->and(TaskDueDate::overdueDaysOf($done, $today))->toBe(0)
        ->and(TaskDueDate::overdueDaysOf($archived, $today))->toBe(0)
        ->and(TaskDueDate::overdueDaysOf($dueToday, $today))->toBe(0)
        ->and(TaskDueDate::overdueDaysOf($later, $today))->toBe(0)
        ->and(TaskDueDate::overdueDays('2026-10-14', '2026-10-14'))->toBe(0)
        ->and(TaskDueDate::overdueDays('2026-10-15', '2026-10-14'))->toBe(0)
        ->and(TaskDueDate::overdueDays('2026-10-12', '2026-10-14'))->toBe(2);
});
