<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * The task creation path: the KEY-N reference from the per-project counter, the
 * board position and the Partner read isolation (TA-01, TA-02). Every title and
 * key is fictional and assembled at runtime.
 */

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function taskSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client-visible project with the given key (or a random one).
 */
function taskProject(?Client $client = null, string $key = '', bool $visible = true): Project
{
    return taskSystem(static fn (): Project => Project::factory()->create([
        'client_id' => ($client ?? Client::factory()->create())->id,
        'key' => $key !== '' ? $key : Canary::projectKey(),
        'client_visible' => $visible,
    ]));
}

/**
 * Creates a task through the Action as the given actor, who is signed in.
 *
 * @param  array<string, mixed>  $data
 */
function taskCreate(User $actor, Project $project, array $data = []): Task
{
    test()->actingAs($actor);

    /** @var array{title: string} $payload */
    $payload = ['title' => 'Example task '.Str::lower(Str::random(6)), ...$data];

    return app(CreateTask::class)->handle($actor, $project, $payload);
}

it('gives the first two tasks of a project the references ABC-1 and ABC-2', function (): void {
    $admin = Canary::admin();
    $project = taskProject(key: 'ABC');

    $first = taskCreate($admin, $project);
    $second = taskCreate($admin, $project);

    expect($first->reference)->toBe('ABC-1')
        ->and($first->number)->toBe(1)
        ->and($second->reference)->toBe('ABC-2')
        ->and($second->number)->toBe(2)
        ->and($first->project_id)->toBe($project->id);
});

it('creates a planned task of normal priority with the Admin as requester and assignee', function (): void {
    $admin = Canary::admin();

    $task = taskCreate($admin, taskProject());

    expect($task->status)->toBe(ProjectStatus::Planned)
        ->and($task->priority)->toBe(ProjectPriority::Normal)
        ->and($task->requester_id)->toBe($admin->id)
        ->and($task->assignee_id)->toBe($admin->id)
        ->and($task->depth)->toBe(0)
        ->and($task->parent_id)->toBeNull()
        ->and($task->completed_at)->toBeNull();
});

it('gives tasks of one status column distinct positions, counting up from zero', function (): void {
    $admin = Canary::admin();
    $project = taskProject();

    $positions = [
        taskCreate($admin, $project)->position,
        taskCreate($admin, $project)->position,
        taskCreate($admin, $project)->position,
    ];

    expect($positions)->toBe([0, 1, 2]);
});

it('counts every project on its own: the first task of project XY is XY-1', function (): void {
    $admin = Canary::admin();
    $abc = taskProject(key: 'ABC');
    $xy = taskProject(key: 'XY');

    taskCreate($admin, $abc);
    taskCreate($admin, $abc);

    expect(taskCreate($admin, $xy)->reference)->toBe('XY-1');
});

it('stores a Done task with a completion time and no column position', function (): void {
    $admin = Canary::admin();
    $project = taskProject();

    $done = taskCreate($admin, $project, ['status' => 'done']);
    $planned = taskCreate($admin, $project);

    expect($done->status)->toBe(ProjectStatus::Done)
        ->and($done->completed_at)->not->toBeNull()
        ->and($done->position)->toBe(0)
        ->and($planned->position)->toBe(0);
});

it('rejects a blank title as a field error and stores nothing', function (): void {
    $admin = Canary::admin();
    $project = taskProject();

    expect(fn () => taskCreate($admin, $project, ['title' => '   ']))
        ->toThrow(ValidationException::class);

    expect(taskSystem(static fn (): int => Task::query()->count()))->toBe(0);
});

it('shows a Partner only the tasks of the own client-visible projects', function (): void {
    $admin = Canary::admin();
    $clientA = taskSystem(static fn (): Client => Client::factory()->create());
    $clientB = taskSystem(static fn (): Client => Client::factory()->create());

    $ownVisible = taskCreate($admin, taskProject($clientA));
    taskCreate($admin, taskProject($clientA, visible: false));
    taskCreate($admin, taskProject($clientB));

    $partner = Canary::partnerFor($clientA->id);
    test()->actingAs($partner);

    expect(Task::query()->pluck('reference')->all())->toBe([$ownVisible->reference]);
});

it('shows a Partner no task of an archived project', function (): void {
    $admin = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $project = taskProject($client);

    taskCreate($admin, $project);
    taskSystem(static fn () => $project->delete());

    test()->actingAs(Canary::partnerFor($client->id));

    expect(Task::query()->count())->toBe(0);
});
