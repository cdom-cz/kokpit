<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\CreatePartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ListPartnerTasks;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Partner task screens "Moje úkoly" (TA-07, KB-03). Every name, key and text
 * is fictional and assembled at runtime; rows are written in system runs.
 */

/**
 * Creates a project of the given client in a system run.
 *
 * @param  array<string, mixed>  $attributes
 */
function partnerTaskResProject(string $clientId, array $attributes = []): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => Project::factory()->create([
        'client_id' => $clientId,
        'key' => Canary::projectKey(),
        'client_visible' => true,
        ...$attributes,
    ]));
}

/**
 * Creates a task of the project as the given actor, in a system run.
 *
 * @param  array<string, mixed>  $data
 */
function partnerTaskResTask(User $actor, Project $project, array $data = []): Task
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): Task => app(CreateTask::class)->handle($actor, $project, ['title' => Canary::canary('task'), ...$data]),
    );
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->projectA = partnerTaskResProject($this->clientA);
    $this->projectB = partnerTaskResProject($this->clientB);
    $this->partnerA = Canary::partnerFor($this->clientA);
});

it('lists the task of an own visible project to a Partner and nothing of client B', function (): void {
    $own = partnerTaskResTask($this->admin, $this->projectA);
    $foreign = partnerTaskResTask($this->admin, $this->projectB);
    $this->actingAs($this->partnerA);

    $body = (string) $this->get('/admin/my-tasks')->assertOk()->getContent();

    expect($body)->toContain($own->title)
        ->not->toContain($foreign->title)
        ->not->toContain($foreign->reference);

    Livewire::test(ListPartnerTasks::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('lets a Partner raise a task in a visible project and lands on its page', function (): void {
    $this->actingAs($this->partnerA);
    $title = Canary::canary('raised');

    $component = Livewire::test(CreatePartnerTask::class)
        ->fillForm(['project_id' => $this->projectA->id, 'title' => $title, 'description' => '<p>Example description</p>'])
        ->call('create')
        ->assertHasNoFormErrors();

    $task = app(PartnerContext::class)->runAsSystem(static fn (): Task => Task::query()->where('title', $title)->firstOrFail());
    $component->assertRedirect('/admin/my-tasks/'.$task->reference);

    expect($task->reference)->toBe($this->projectA->key.'-1')
        ->and($task->status->value)->toBe('planned')
        ->and($task->priority->value)->toBe('normal')
        ->and($task->requester_id)->toBe($this->partnerA->id)
        ->and($task->assignee_id)->toBe($this->admin->id);

    $this->get('/admin/my-tasks/'.$task->reference)
        ->assertOk()
        ->assertSee($title)
        ->assertSee($task->reference);
});

it('gives the Admin 403 on the Partner task list and create page', function (): void {
    $this->actingAs($this->admin);

    $this->get('/admin/my-tasks')->assertForbidden();
    $this->get('/admin/my-tasks/create')->assertForbidden();
});
