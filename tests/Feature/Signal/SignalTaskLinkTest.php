<?php

declare(strict_types=1);

use App\Domain\Projects\Models\Project;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Tasks\Models\Task;
use App\Livewire\Signal\SignalDay;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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
