<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Tests\Support\Canary;

/*
 * What a Partner sees of projects (PR-04, D-07, D-11): exactly the projects of
 * the own client that are flagged client-visible and not archived, while the
 * client itself is not archived. Rows are written in system runs; every
 * name and key is fictional and assembled at runtime.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function projectVisibilitySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Creates a project of the given client in a system run.
 *
 * @param  array<string, mixed>  $attributes
 */
function projectVisibilityProject(string $clientId, array $attributes = []): Project
{
    return projectVisibilitySystem(static fn (): Project => Project::factory()->create([
        'client_id' => $clientId,
        'key' => Canary::projectKey(),
        ...$attributes,
    ]));
}

/**
 * The names of the projects the current user sees, sorted.
 *
 * @return list<string>
 */
function projectVisibilityNames(): array
{
    $names = Project::query()->pluck('name')->all();
    sort($names, SORT_STRING);

    return array_values($names);
}

beforeEach(function (): void {
    [$this->clientA, $this->clientB] = Canary::twoClients();

    $this->visibleA = projectVisibilityProject($this->clientA, ['name' => Canary::canary('visible_a'), 'client_visible' => true]);
    $this->hiddenA = projectVisibilityProject($this->clientA, ['name' => Canary::canary('hidden_a'), 'client_visible' => false]);
    $this->visibleB = projectVisibilityProject($this->clientB, ['name' => Canary::canary('visible_b'), 'client_visible' => true]);
    $this->hiddenB = projectVisibilityProject($this->clientB, ['name' => Canary::canary('hidden_b'), 'client_visible' => false]);
});

describe('Partner query', function (): void {
    it('shows Partner A exactly the own client-visible project', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(projectVisibilityNames())->toBe([$this->visibleA->name])
            ->and(Project::query()->count())->toBe(1);
    });

    it('does not show Partner A the own hidden project, not even by its id', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(Project::query()->find($this->hiddenA->id))->toBeNull()
            ->and(Project::query()->whereKey($this->hiddenA->id)->exists())->toBeFalse();
    });

    it('does not show Partner A a visible project of client B, not even by its id', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(Project::query()->find($this->visibleB->id))->toBeNull()
            ->and(projectVisibilityNames())->not->toContain($this->visibleB->name)
            ->and(Project::query()->where('client_id', $this->clientB)->count())->toBe(0);
    });

    it('keeps the constraint when the Partner adds an or-condition', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $names = Project::query()
            ->where('client_id', $this->clientA)
            ->orWhere('client_id', $this->clientB)
            ->orWhere('client_visible', false)
            ->pluck('name')
            ->all();

        expect($names)->toBe([$this->visibleA->name]);
    });

    it('hides an archived project of the own client', function (): void {
        projectVisibilitySystem(fn () => $this->visibleA->delete());

        $this->actingAs(Canary::partnerFor($this->clientA));

        expect(projectVisibilityNames())->toBe([]);
    });

    it('hides every project of an archived client and shows them again after the client is restored', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        projectVisibilitySystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());
        $this->actingAs($partner);

        expect(projectVisibilityNames())->toBe([]);

        projectVisibilitySystem(fn () => Client::withTrashed()->whereKey($this->clientA)->firstOrFail()->restore());

        expect(projectVisibilityNames())->toBe([$this->visibleA->name]);
    });

    it('shows the Partner of client B only the visible project of client B', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientB));

        expect(projectVisibilityNames())->toBe([$this->visibleB->name]);
    });

    it('returns null for the client of a project, because Client stays closed to Partners (D-06)', function (): void {
        $this->actingAs(Canary::partnerFor($this->clientA));

        $project = Project::query()->firstOrFail();

        expect($project->client)->toBeNull()
            ->and(Project::query()->with('client')->firstOrFail()->client)->toBeNull();
    });
});

describe('Admin and system run', function (): void {
    it('shows the Admin every project including hidden and other-client ones', function (): void {
        $this->actingAs(Canary::admin());

        $expected = [$this->visibleA->name, $this->hiddenA->name, $this->visibleB->name, $this->hiddenB->name];
        sort($expected, SORT_STRING);

        expect(projectVisibilityNames())->toBe($expected)
            ->and($this->visibleA->fresh()?->client?->id)->toBe($this->clientA);
    });

    it('shows the Admin the projects of an archived client', function (): void {
        projectVisibilitySystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());

        $this->actingAs(Canary::admin());

        expect(Project::query()->count())->toBe(4);
    });

    it('shows a system run every project without a user', function (): void {
        expect(projectVisibilitySystem(static fn (): int => Project::query()->count()))->toBe(4)
            ->and(Project::query()->count())->toBe(0);
    });
});

describe('fail-closed states', function (): void {
    it('shows a guest no project', function (): void {
        expect(Project::query()->count())->toBe(0);
    });

    it('shows a user with a client but no role no project', function (): void {
        $this->actingAs(Canary::userWithoutRole($this->clientA));

        expect(Project::query()->count())->toBe(0);
    });

    it('shows a Partner without a client no project', function (): void {
        $this->actingAs(Canary::partnerFor(null));

        expect(Project::query()->count())->toBe(0);
    });
});

describe('ProjectPolicy', function (): void {
    it('grants a Partner viewAny and view of the own client-visible project', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        expect($partner->can('viewAny', Project::class))->toBeTrue()
            ->and($partner->can('view', $this->visibleA))->toBeTrue();
    });

    it('denies a Partner view of a hidden project and of a project of another client', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        expect($partner->can('view', $this->hiddenA))->toBeFalse()
            ->and($partner->can('view', $this->visibleB))->toBeFalse()
            ->and($partner->can('view', $this->hiddenB))->toBeFalse();
    });

    it('denies a Partner every write ability on every project', function (): void {
        $partner = Canary::partnerFor($this->clientA);

        expect($partner->can('create', Project::class))->toBeFalse();

        foreach ([$this->visibleA, $this->hiddenA, $this->visibleB, $this->hiddenB] as $project) {
            foreach (['update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
                expect($partner->can($ability, $project))->toBeFalse("{$ability} must be denied for a Partner");
            }
        }
    });

    it('denies a Partner without a client and a role-less user everything', function (): void {
        foreach ([Canary::partnerFor(null), Canary::userWithoutRole($this->clientA)] as $user) {
            expect($user->can('viewAny', Project::class))->toBeFalse()
                ->and($user->can('view', $this->visibleA))->toBeFalse()
                ->and($user->can('create', Project::class))->toBeFalse();
        }
    });

    it('lets the Admin do everything', function (): void {
        $admin = Canary::admin();

        expect($admin->can('viewAny', Project::class))->toBeTrue()
            ->and($admin->can('create', Project::class))->toBeTrue();

        foreach ([$this->visibleA, $this->hiddenA, $this->visibleB, $this->hiddenB] as $project) {
            foreach (['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
                expect($admin->can($ability, $project))->toBeTrue("{$ability} must be allowed for the Admin");
            }
        }
    });
});

it('does not make client_id fillable, so mass assignment cannot move a project to another client', function (): void {
    expect((new Project)->getFillable())->not->toContain('client_id')
        ->and(fn () => new Project(['name' => 'Example project', 'client_id' => $this->clientB]))
        ->toThrow(MassAssignmentException::class);
});
