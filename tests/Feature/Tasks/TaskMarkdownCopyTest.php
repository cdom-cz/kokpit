<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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

it('has a Czech text for every key of the modal', function (): void {
    foreach (['action', 'heading', 'description', 'label', 'copy', 'copied', 'close'] as $key) {
        $full = 'kokpit.tasks.markdown.'.$key;

        expect(__($full))->toBeString()->not->toBe($full)->not->toBe('');
    }
});

it('wires the modal to the clipboard', function (): void {
    $task = taskMdTask('Example markdown task', '<p>Example body</p>');

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->mountAction('copyMarkdown')
        ->assertMountedActionModalSee([__('kokpit.tasks.markdown.heading'), __('kokpit.tasks.markdown.copy')])
        ->assertMountedActionModalSeeHtml(['navigator.clipboard', 'mountedActions[0].data.markdown']);
});

it('gives only the heading for a task without a description', function (): void {
    $task = taskMdTask('Example empty task', null, key: 'MDE');

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->mountAction('copyMarkdown')
        ->assertActionDataSet(['markdown' => "# Example empty task\n"]);
});

it('stays available on an archived task and gives the same Markdown', function (): void {
    $task = taskMdTask('Example archived task', '<p>Example body</p>', key: 'MDA');

    $before = Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->mountAction('copyMarkdown')
        ->get('mountedActions.0.data.markdown');

    app(ArchiveTask::class)->handle($this->admin, $task);

    Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->assertActionVisible('copyMarkdown')
        ->mountAction('copyMarkdown')
        ->assertActionDataSet(['markdown' => $before]);

    expect($before)->toBe("# Example archived task\n\nExample body\n");
});

it('cleans a description forged straight into the row', function (): void {
    $task = taskMdTask('Example forged task', '<p>Example body</p>', key: 'MDF');

    $lt = '<';

    DB::table('tasks')->where('id', $task->getKey())->update(['description' => implode('', [
        $lt.'scr'.'ipt>window.markerOne()'.$lt.'/scr'.'ipt>',
        $lt.'img src="https://example.com/marker'.'Two.png" on'.'error="markerThree()">',
        $lt.'p>Benign words'.$lt.'/p>',
    ])]);

    $markdown = Livewire::test(ViewTask::class, ['record' => $task->reference])
        ->mountAction('copyMarkdown')
        ->get('mountedActions.0.data.markdown');

    expect($markdown)->toBeString()
        ->toContain('Benign words')
        ->not->toContain('markerOne')
        ->not->toContain('markerTwo')
        ->not->toContain('markerThree')
        ->not->toContain('<script')
        ->not->toContain('<img');
});

it('refuses a Partner the Admin task page', function (): void {
    $task = taskMdTask('Example partner task', '<p>Example body</p>', key: 'MDP');
    $partner = Canary::partnerFor(Project::query()->findOrFail($task->project_id)->client_id);

    $this->actingAs($partner)->get(TaskResource::getUrl('view', ['record' => $task->reference]))->assertForbidden();
});

it('hides the action when the session switched to a Partner after the page was mounted', function (): void {
    $task = taskMdTask('Example switched task', '<p>Example body</p>', key: 'MDS');
    $partner = Canary::partnerFor(Project::query()->findOrFail($task->project_id)->client_id);

    $component = Livewire::test(ViewTask::class, ['record' => $task->reference])->assertActionVisible('copyMarkdown');

    $this->actingAs($partner);

    $component->assertActionHidden('copyMarkdown');
});

it('gives the Partner task page no copyMarkdown action', function (): void {
    $task = taskMdTask('Example visible task', '<p>Example body</p>', key: 'MDV');
    $partner = Canary::partnerFor(Project::query()->findOrFail($task->project_id)->client_id);

    $this->actingAs($partner);

    Livewire::test(ViewPartnerTask::class, ['record' => $task->reference])->assertActionDoesNotExist('copyMarkdown');
});
