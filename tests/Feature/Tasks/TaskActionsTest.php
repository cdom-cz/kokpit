<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
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

/**
 * The field errors of a callable that must throw a ValidationException.
 *
 * @return array<string, list<string>>
 */
function taskErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * A Partner account of the client, active unless told otherwise.
 */
function taskPartner(Client $client, bool $active = true): User
{
    $partner = Canary::partnerFor($client->id);

    if (! $active) {
        $partner->forceFill(['deactivated_at' => now()])->save();
    }

    return $partner;
}

it('stores an explicit active Partner of the project client as assignee and requester', function (): void {
    $admin = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $partner = taskPartner($client);

    $task = taskCreate($admin, taskProject($client), ['assignee_id' => $partner->id, 'requester_id' => $partner->id]);

    expect($task->assignee_id)->toBe($partner->id)
        ->and($task->requester_id)->toBe($partner->id);
});

it('accepts another active Admin as assignee while the requester defaults to the actor', function (): void {
    $admin = Canary::admin();
    $colleague = Canary::admin();

    $task = taskCreate($admin, taskProject(), ['assignee_id' => $colleague->id]);

    expect($task->assignee_id)->toBe($colleague->id)
        ->and($task->requester_id)->toBe($admin->id);
});

it('refuses a person outside the allowed set as a field error and keeps the counter unchanged', function (string $field, string $kind): void {
    $admin = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $other = taskSystem(static fn (): Client => Client::factory()->create());
    $project = taskProject($client, 'ABC');

    $candidate = match ($kind) {
        'other client' => taskPartner($other)->id,
        'deactivated partner' => taskPartner($client, active: false)->id,
        'deactivated admin' => (static function (): string {
            $retired = Canary::admin();
            $retired->forceFill(['deactivated_at' => now()])->save();

            return $retired->id;
        })(),
        'without a role' => Canary::userWithoutRole($client->id)->id,
        'unknown id' => (string) Str::uuid(),
    };

    $errors = taskErrors(fn () => taskCreate($admin, $project, [$field => $candidate]));

    expect($errors)->toHaveKey($field)
        ->and(array_keys($errors))->toBe([$field])
        ->and(taskSystem(static fn (): int => Task::query()->count()))->toBe(0)
        ->and(taskCreate($admin, $project)->reference)->toBe('ABC-1');
})->with([
    'assignee' => ['assignee_id'],
    'requester' => ['requester_id'],
])->with([
    'other client' => ['other client'],
    'deactivated partner' => ['deactivated partner'],
    'deactivated admin' => ['deactivated admin'],
    'without a role' => ['without a role'],
    'unknown id' => ['unknown id'],
]);

it('gives a Partner task the Partner as requester, the Admin as assignee, planned and normal', function (): void {
    $admin = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $partner = taskPartner($client);
    $other = taskPartner($client);

    $task = taskCreate($partner, taskProject($client), [
        'status' => 'done',
        'priority' => 'urgent',
        'assignee_id' => $other->id,
        'requester_id' => $other->id,
    ]);

    expect($task->requester_id)->toBe($partner->id)
        ->and($task->assignee_id)->toBe($admin->id)
        ->and($task->status)->toBe(ProjectStatus::Planned)
        ->and($task->priority)->toBe(ProjectPriority::Normal)
        ->and($task->completed_at)->toBeNull();
});

it('drops the tags of a Partner payload and stores the same tags for the Admin', function (): void {
    $admin = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $partner = taskPartner($client);
    $project = taskProject($client);
    $name = Canary::canary('tag');

    $byPartner = taskCreate($partner, $project, ['tags' => [$name]]);

    // Read in a system run: the Partner tag scope would hide every task tag and make this vacuous.
    $partnerTags = taskSystem(static fn (): array => $byPartner->tags()->pluck('name')->all());
    $partnerRows = taskSystem(static fn (): int => Tag::query()->where('type', TagType::Task->value)->count());

    expect($byPartner->requester_id)->toBe($partner->id)
        ->and($partnerTags)->toBe([])
        ->and($partnerRows)->toBe(0);

    $byAdmin = taskCreate($admin, $project, ['tags' => [$name]]);

    expect(taskSystem(static fn (): array => $byAdmin->tags()->pluck('name')->all()))->toBe([$name])
        ->and(taskSystem(static fn (): int => Tag::query()->where('type', TagType::Task->value)->count()))->toBe(1);
});

it('writes no tag when a Partner creation with tags is refused', function (): void {
    Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $partner = taskPartner($client);
    $foreign = taskProject(Client::factory()->create(), 'ABC');
    $name = Canary::canary('tag');

    $errors = taskErrors(fn () => taskCreate($partner, $foreign, ['tags' => [$name]]));

    expect($errors)->toHaveKey('project_id')
        ->and(taskSystem(static fn (): int => Task::query()->count()))->toBe(0)
        ->and(taskSystem(static fn (): int => Tag::query()->count()))->toBe(0);
});

it('takes the oldest active Admin as the assignee of a Partner task', function (): void {
    $oldest = Canary::admin();
    $oldest->forceFill(['created_at' => now()->subDays(2)])->save();
    $newer = Canary::admin();
    $newer->forceFill(['created_at' => now()->subDay()])->save();
    $client = taskSystem(static fn (): Client => Client::factory()->create());

    $task = taskCreate(taskPartner($client), taskProject($client));

    expect($task->assignee_id)->toBe($oldest->id);
});

it('skips a deactivated Admin when it picks the assignee of a Partner task', function (): void {
    $retired = Canary::admin();
    $retired->forceFill(['created_at' => now()->subDays(2), 'deactivated_at' => now()])->save();
    $active = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());

    $task = taskCreate(taskPartner($client), taskProject($client));

    expect($task->assignee_id)->toBe($active->id);
});

it('fails a Partner creation with a DomainException when no active Admin exists, and stores nothing', function (): void {
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $partner = taskPartner($client);
    $project = taskProject($client);

    expect(fn () => taskCreate($partner, $project))->toThrow(DomainException::class);
    expect(taskSystem(static fn (): int => Task::query()->count()))->toBe(0);
});

it('refuses every project a Partner may not use with the same field error and consumes no number', function (string $case): void {
    $admin = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $other = taskSystem(static fn (): Client => Client::factory()->create());
    $partner = taskPartner($client);

    $project = match ($case) {
        'other client' => taskProject($other, 'ABC'),
        'not client-visible' => taskProject($client, 'ABC', visible: false),
        'archived project' => (static function () use ($client): Project {
            $project = taskProject($client, 'ABC');
            taskSystem(static fn () => $project->delete());

            return $project;
        })(),
        'archived client' => (static function () use ($client): Project {
            $project = taskProject($client, 'ABC');
            taskSystem(static fn () => $client->delete());

            return $project;
        })(),
    };

    $errors = taskErrors(fn () => taskCreate($partner, $project));

    expect($errors)->toHaveKey('project_id')
        ->and($errors['project_id'][0])->toBe(__('kokpit.tasks.errors.project_unavailable'));

    // The Admin may only create in a project that is not archived; for the cases the
    // Partner was refused on visibility alone, the counter proves no number was consumed.
    if (in_array($case, ['other client', 'not client-visible'], true)) {
        expect(taskCreate($admin, $project)->reference)->toBe('ABC-1');
    }
})->with(['other client', 'not client-visible', 'archived project', 'archived client']);

it('refuses the Admin an archived project or a project of an archived client with the same field error', function (string $case): void {
    $admin = Canary::admin();
    $client = taskSystem(static fn (): Client => Client::factory()->create());
    $project = taskProject($client);

    taskSystem(static fn () => $case === 'archived project' ? $project->delete() : $client->delete());

    $errors = taskErrors(fn () => taskCreate($admin, $project));

    expect($errors)->toHaveKey('project_id')
        ->and($errors['project_id'][0])->toBe(__('kokpit.tasks.errors.project_unavailable'))
        ->and(taskSystem(static fn (): int => Task::query()->count()))->toBe(0);
})->with(['archived project', 'archived client']);

it('gives the same number again when a transaction around CreateTask is rolled back', function (): void {
    $admin = Canary::admin();
    $project = taskProject(key: 'ABC');

    try {
        DB::transaction(function () use ($admin, $project): void {
            expect(taskCreate($admin, $project)->reference)->toBe('ABC-1');

            throw new RuntimeException('interrupted run');
        });
    } catch (RuntimeException) {
        // The outer transaction was rolled back, the task and its number with it.
    }

    expect(taskSystem(static fn (): int => Task::query()->count()))->toBe(0)
        ->and(taskCreate($admin, $project)->reference)->toBe('ABC-1');
});
