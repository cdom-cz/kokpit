<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin copies a task's assignment as Markdown from the task page: a modal previews the H1
 * with the task name (never the KEY-N reference) and the description converted from HTML, and a
 * button copies exactly that text. The modal writes nothing. Every value is fictional.
 */

/**
 * A task with the given title and description (cleaned by the Action), written as the signed-in
 * Admin. A later case passes its own project, and a distinct key, to stay clear of other numbers.
 */
function taskMdTask(string $title, ?string $description, ?Project $project = null, string $key = 'MDC'): Task
{
    $project ??= app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example markdown project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);

    $task = app(CreateTask::class)->handle(test()->admin, $project, ['title' => $title]);

    if ($description !== null) {
        $task = app(UpdateTask::class)->handle(test()->admin, $task, ['description' => $description]);
    }

    return $task;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('opens a preview with the title as the H1 and the converted description', function (): void {
    $task = taskMdTask(
        'Example markdown task',
        '<h2>Example heading</h2><p><strong>Example bold</strong> and plain words</p>',
    );

    $expected = implode("\n", [
        '# Example markdown task',
        '',
        '## Example heading',
        '',
        '**Example bold** and plain words',
    ])."\n";

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionVisible('copyMarkdown')
        ->mountAction('copyMarkdown')
        ->assertActionMounted('copyMarkdown')
        ->assertActionDataSet(['markdown' => $expected]);
});

it('never puts the task reference in the Markdown', function (): void {
    $task = taskMdTask('Example markdown task', '<p>Example body</p>');

    $component = Livewire::test(ViewTask::class, ['record' => $task->reference])->mountAction('copyMarkdown');

    $markdown = $component->get('mountedActions.0.data.markdown');

    expect($markdown)->toBeString()->not->toContain($task->reference);
});
