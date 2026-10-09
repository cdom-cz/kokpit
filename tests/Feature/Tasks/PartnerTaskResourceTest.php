<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Actions\UpdateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\CreatePartnerTask;
use App\Filament\Partner\Resources\PartnerTaskResource\Pages\ListPartnerTasks;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
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

/**
 * Marker words of the description payload, none of which may survive.
 *
 * @return list<string>
 */
function partnerTaskResMarkers(): array
{
    return ['markerAlpha', 'markerBeta', 'markerGamma', 'markerDelta', 'markerEpsilon'];
}

/**
 * A description assembled from fragments at runtime, so no single line of this
 * file looks like a payload constant.
 */
function partnerTaskResPayload(): string
{
    $lt = '<';

    return implode('', [
        $lt.'scr'.'ipt>window.markerAlpha()'.$lt.'/scr'.'ipt>',
        $lt.'img src="x" on'.'error="markerBeta()">',
        $lt.'a href="java'.'script:markerGamma()">Example link words'.$lt.'/a>',
        $lt.'p style="position:'.'fixed;top:markerDelta" class="markerEpsilon">Benign words'.$lt.'/p>',
    ]);
}

/**
 * The HTML of a Livewire component without its snapshot attribute, which carries the raw state.
 */
function partnerTaskResHtml(string $html): string
{
    return (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);
}

it('stores and shows a Partner description without script, handler, script link and style, keeping the words (D-10)', function (): void {
    $this->actingAs($this->partnerA);
    $title = Canary::canary('payload');

    Livewire::test(CreatePartnerTask::class)
        ->fillForm(['project_id' => $this->projectA->id, 'title' => $title, 'description' => partnerTaskResPayload()])
        ->call('create')
        ->assertHasNoFormErrors();

    $task = app(PartnerContext::class)->runAsSystem(static fn (): Task => Task::query()->where('title', $title)->firstOrFail());
    $page = partnerTaskResHtml((string) $this->get('/admin/my-tasks/'.$task->reference)->assertOk()->getContent());

    $this->actingAs($this->admin);
    $adminPage = partnerTaskResHtml((string) $this->get('/admin/tasks/'.$task->reference)->assertOk()->getContent());

    foreach ([(string) $task->description, $page, $adminPage] as $text) {
        expect($text)->toContain('Benign words')->toContain('Example link words');

        foreach (partnerTaskResMarkers() as $marker) {
            expect($text)->not->toContain($marker);
        }
    }

    expect((string) $task->description)->not->toContain('<script')->not->toContain('<img')->not->toContain('style=')->not->toContain('javascript:');
});

it('shows the empty state to a Partner whose client has no client-visible project and offers no project', function (): void {
    $lonely = Canary::partnerFor(app(PartnerContext::class)->runAsSystem(static fn (): string => Client::factory()->create()->id));
    $this->actingAs($lonely);

    $this->get('/admin/my-tasks')
        ->assertOk()
        ->assertSee('Zatím tu nejsou žádné úkoly')
        ->assertSee('Nový úkol');

    expect(PartnerTaskResource::projectOptions())->toBe([]);

    $title = Canary::canary('lonely');

    Livewire::test(CreatePartnerTask::class)
        ->fillForm(['project_id' => $this->projectA->id, 'title' => $title])
        ->call('create')
        ->assertHasFormErrors(['project_id']);

    expect(app(PartnerContext::class)->runAsSystem(static fn (): int => Task::query()->where('title', $title)->count()))->toBe(0);
});

it('refuses a forged project id of client B or of a hidden own project with the project field error and takes no number', function (): void {
    $hidden = partnerTaskResProject($this->clientA, ['client_visible' => false]);
    $this->actingAs($this->partnerA);

    foreach ([$this->projectB, $hidden] as $project) {
        $title = Canary::canary('forged_project');

        Livewire::test(CreatePartnerTask::class)
            ->fillForm(['title' => $title])
            ->set('data.project_id', $project->id)
            ->call('create')
            ->assertHasFormErrors(['project_id']);

        expect(app(PartnerContext::class)->runAsSystem(static fn (): int => Task::query()->where('title', $title)->count()))->toBe(0);
    }

    expect(PartnerTaskResource::projectOptions())->toBe([$this->projectA->id => $this->projectA->key.' · '.$this->projectA->name]);
});

it('drops the tasks of a project from the Partner list and page on the next request once the project is not client-visible', function (): void {
    $project = app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(
        Client::query()->findOrFail(test()->clientA),
        ['name' => Canary::canary('switch'), 'key' => Canary::projectKey(), 'billing_type' => 'hourly', 'client_visible' => true],
    ));
    $task = partnerTaskResTask($this->admin, $project);
    $this->actingAs($this->partnerA);

    $this->get('/admin/my-tasks')->assertOk()->assertSee($task->title);
    $this->get('/admin/my-tasks/'.$task->reference)->assertOk();

    app(PartnerContext::class)->runAsSystem(static fn () => app(UpdateProject::class)->handle($project, ['client_visible' => false]));

    expect((string) $this->get('/admin/my-tasks')->assertOk()->getContent())->not->toContain($task->title);
    $this->get('/admin/my-tasks/'.$task->reference)->assertNotFound();
    expect(PartnerTaskResource::projectOptions())->not->toHaveKey($project->id);

    app(PartnerContext::class)->runAsSystem(static fn () => app(UpdateProject::class)->handle($project->refresh(), ['client_visible' => true]));

    $this->get('/admin/my-tasks/'.$task->reference)->assertOk();
});

it('orders the Partner list by creation time descending with ties broken by id descending', function (): void {
    $first = partnerTaskResTask($this->admin, $this->projectA);
    $second = partnerTaskResTask($this->admin, $this->projectA);
    $third = partnerTaskResTask($this->admin, $this->projectA);
    $fourth = partnerTaskResTask($this->admin, $this->projectA);

    // The first two share the very second of creation, so only the id can order them.
    app(PartnerContext::class)->runAsSystem(static function () use ($first, $second, $third, $fourth): void {
        DB::table('tasks')->whereIn('id', [$first->id, $second->id])->update(['created_at' => '2026-01-01 10:00:00+00']);
        DB::table('tasks')->where('id', $third->id)->update(['created_at' => '2026-01-02 10:00:00+00']);
        DB::table('tasks')->where('id', $fourth->id)->update(['created_at' => '2026-01-03 10:00:00+00']);
    });

    $tied = [$first, $second];
    usort($tied, static fn (Task $a, Task $b): int => strcmp($b->id, $a->id));

    $this->actingAs($this->partnerA);

    Livewire::test(ListPartnerTasks::class)
        ->assertCanSeeTableRecords([$fourth, $third, ...$tied], inOrder: true);
});

it('cleans a description that was written around the Action again when the Partner page renders it', function (): void {
    $task = partnerTaskResTask($this->admin, $this->projectA);

    app(PartnerContext::class)->runAsSystem(static fn () => DB::table('tasks')->where('id', $task->id)->update(['description' => partnerTaskResPayload()]));

    $this->actingAs($this->partnerA);
    $page = partnerTaskResHtml((string) $this->get('/admin/my-tasks/'.$task->reference)->assertOk()->getContent());

    expect($page)->toContain('Benign words');

    foreach (partnerTaskResMarkers() as $marker) {
        expect($page)->not->toContain($marker);
    }
});

it('shows the escalation entry with the name and the time only while the task is escalated', function (): void {
    $task = partnerTaskResTask($this->admin, $this->projectA);
    $this->actingAs($this->partnerA);

    expect((string) $this->get('/admin/my-tasks/'.$task->reference)->assertOk()->getContent())->not->toContain('Eskalace');

    app(PartnerContext::class)->runAsSystem(static fn () => DB::table('tasks')->where('id', $task->id)->update([
        'escalated_at' => '2026-01-05 09:30:00+00',
        'escalated_by_id' => test()->partnerA->id,
    ]));

    $page = (string) $this->get('/admin/my-tasks/'.$task->reference)->assertOk()->getContent();

    expect($page)->toContain('Eskalace')
        ->toContain('Eskalováno (')
        ->toContain($this->partnerA->name);
});
