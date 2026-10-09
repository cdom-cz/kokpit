<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * The project key freeze and the never-recycled task numbers (PR-02, TA-02).
 * Once a project has any task, an archived one included, its key cannot change:
 * not through the database, not through the Action, not in the Admin form.
 * Every name and key is fictional.
 */

/**
 * A project with the given key and its billing row, written through the domain Action.
 */
function keyFreezeProject(string $key = 'ABC'): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example frozen project',
        'key' => $key,
        'billing_type' => 'hourly',
    ]);
}

/**
 * Creates a task through the Action as the signed-in Admin.
 */
function keyFreezeTask(User $admin, Project $project): Task
{
    return app(CreateTask::class)->handle($admin, $project, ['title' => 'Example task']);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('refuses a raw key update on a project with a task with SQLSTATE KP002', function (): void {
    $project = keyFreezeProject();
    keyFreezeTask($this->admin, $project);

    RawSql::expectSqlState('KP002', static fn () => DB::update('UPDATE projects SET "key" = ? WHERE id = ?', ['ZZZ', $project->id]));

    expect(DB::table('projects')->where('id', $project->id)->value('key'))->toBe('ABC');
});

it('refuses a raw key update on a project that only has an archived task', function (): void {
    $project = keyFreezeProject();
    keyFreezeTask($this->admin, $project)->delete();

    expect(Task::query()->where('project_id', $project->id)->count())->toBe(0);

    RawSql::expectSqlState('KP002', static fn () => DB::update('UPDATE projects SET "key" = ? WHERE id = ?', ['ZZZ', $project->id]));
});

it('lets raw SQL change the key of a project without tasks and other columns of a project with tasks', function (): void {
    $free = keyFreezeProject('FRE');
    $busy = keyFreezeProject('BUS');
    keyFreezeTask($this->admin, $busy);

    RawSql::expectAllowed(static fn () => DB::update('UPDATE projects SET "key" = ? WHERE id = ?', ['NEW', $free->id]));
    RawSql::expectAllowed(static fn () => DB::update('UPDATE projects SET name = ?, "key" = "key" WHERE id = ?', ['Example renamed', $busy->id]));

    expect(DB::table('projects')->where('id', $free->id)->value('key'))->toBe('NEW')
        ->and(DB::table('projects')->where('id', $busy->id)->value('name'))->toBe('Example renamed');
});

it('changes the key of a project without tasks through UpdateProject', function (): void {
    $project = keyFreezeProject();

    app(UpdateProject::class)->handle($project, ['key' => 'xyz']);

    expect(Project::query()->findOrFail($project->id)->key)->toBe('XYZ');
});

it('answers a field error on key for a new key on a project with tasks and leaves the key', function (): void {
    $project = keyFreezeProject();
    keyFreezeTask($this->admin, $project);

    try {
        app(UpdateProject::class)->handle($project->refresh(), ['key' => 'ZZZ', 'name' => 'Example renamed']);

        $this->fail('Expected a ValidationException on key.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('key')
            ->and($e->errors()['key'][0])->toBe(__('kokpit.projects.errors.key_frozen'));
    }

    $fresh = Project::query()->findOrFail($project->id);

    expect($fresh->key)->toBe('ABC')->and($fresh->name)->toBe('Example frozen project');
});

it('answers the same field error when only an archived task keeps the key frozen', function (): void {
    $project = keyFreezeProject();
    keyFreezeTask($this->admin, $project)->delete();

    expect(fn () => app(UpdateProject::class)->handle($project->refresh(), ['key' => 'ZZZ']))
        ->toThrow(ValidationException::class);

    expect(Project::query()->findOrFail($project->id)->key)->toBe('ABC');
});

it('lets a project with tasks keep its own key while other fields change', function (): void {
    $project = keyFreezeProject();
    keyFreezeTask($this->admin, $project);

    app(UpdateProject::class)->handle($project->refresh(), ['key' => 'abc', 'name' => 'Example renamed']);

    $fresh = Project::query()->findOrFail($project->id);

    expect($fresh->key)->toBe('ABC')->and($fresh->name)->toBe('Example renamed');
});

it('translates a task created between the check and the update to the same field error', function (): void {
    $project = keyFreezeProject();
    $raced = false;

    // The Action asks "does the project have tasks" first; the other request creates one right after that answer.
    DB::listen(function (QueryExecuted $query) use (&$raced, $project): void {
        if ($raced || ! str_contains($query->sql, 'from "tasks"') || ! in_array($project->id, $query->bindings, true)) {
            return;
        }

        $raced = true;
        Task::factory()->create(['project_id' => $project->id, 'assignee_id' => $this->admin->id, 'requester_id' => $this->admin->id]);
    });

    try {
        app(UpdateProject::class)->handle($project->refresh(), ['key' => 'ZZZ']);

        $this->fail('Expected a ValidationException on key.');
    } catch (ValidationException $e) {
        expect($e->errors()['key'][0])->toBe(__('kokpit.projects.errors.key_frozen'));
    }

    expect($raced)->toBeTrue()
        ->and(Project::query()->findOrFail($project->id)->key)->toBe('ABC');
});

it('shows the key field disabled with a hint on the edit page of a project with a task', function (): void {
    $project = keyFreezeProject();
    keyFreezeTask($this->admin, $project);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormFieldIsDisabled('key')
        ->assertSee(__('kokpit.projects.hints.key_frozen'))
        ->fillForm(['name' => 'Example renamed in the form'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = Project::query()->findOrFail($project->id);

    expect($fresh->key)->toBe('ABC')->and($fresh->name)->toBe('Example renamed in the form');
});

it('keeps the key field editable on the edit page of a project without tasks', function (): void {
    $project = keyFreezeProject();

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormFieldIsEnabled('key')
        ->assertDontSee(__('kokpit.projects.hints.key_frozen'))
        ->fillForm(['key' => 'NEW'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Project::query()->findOrFail($project->id)->key)->toBe('NEW');
});

it('never hands out the number of an archived task again', function (): void {
    $project = keyFreezeProject();

    $first = keyFreezeTask($this->admin, $project);
    $second = keyFreezeTask($this->admin, $project);

    expect($first->reference)->toBe('ABC-1')->and($second->reference)->toBe('ABC-2');

    $second->delete();

    $third = keyFreezeTask($this->admin, $project);

    expect($third->reference)->toBe('ABC-3')
        ->and($third->number)->toBe(3)
        ->and(Task::query()->withTrashed()->where('project_id', $project->id)->count())->toBe(3);
});
