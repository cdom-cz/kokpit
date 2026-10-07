<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\KokpitPolicy;
use App\Domain\Shared\Policies\AdminOnlyPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;
use Tests\Support\CanaryRecord;

/*
 * The default-deny policy base (D-02): the Admin is admitted by the single
 * rule in before(), a Partner only by an explicit grant, everybody else never.
 */

const MODEL_ABILITIES = ['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate'];
const CLASS_ABILITIES = ['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'];

beforeEach(function (): void {
    Canary::createTable();

    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->recordA = Canary::record($this->clientA, Canary::canary('A'));
    $this->recordB = Canary::record($this->clientB, Canary::canary('B'));
});

/**
 * Every standard ability against a record and against the model class.
 *
 * @return array<string, bool>
 */
function abilityMatrix(?object $user, object|string $record, string $class): array
{
    $gate = Gate::forUser($user);
    $matrix = [];

    foreach (MODEL_ABILITIES as $ability) {
        $matrix[$ability] = $gate->allows($ability, $record);
    }

    foreach (CLASS_ABILITIES as $ability) {
        $matrix[$ability] = $gate->allows($ability, $class);
    }

    return $matrix;
}

it('denies a guest every ability', function (): void {
    $matrix = abilityMatrix(null, $this->recordA, CanaryRecord::class);

    expect($matrix)->toHaveCount(12)
        ->and(array_filter($matrix))->toBe([]);
});

it('admits the Admin to every ability through the one rule in before()', function (): void {
    $matrix = abilityMatrix(Canary::admin(), $this->recordB, CanaryRecord::class);

    expect($matrix)->toHaveCount(12)
        ->and(array_filter($matrix))->toHaveCount(12);
});

it('denies a user without a role every ability', function (): void {
    $matrix = abilityMatrix(Canary::userWithoutRole($this->clientA), $this->recordA, CanaryRecord::class);

    expect(array_filter($matrix))->toBe([]);
});

it('denies a user with an unknown role every ability', function (): void {
    $matrix = abilityMatrix(Canary::userWithRole('auditor', $this->clientA), $this->recordA, CanaryRecord::class);

    expect(array_filter($matrix))->toBe([]);
});

it('denies a Partner without a client every ability', function (): void {
    $matrix = abilityMatrix(Canary::partnerFor(null), $this->recordA, CanaryRecord::class);

    expect(array_filter($matrix))->toBe([]);
});

it('grants a Partner only viewAny and view of a record of their own client', function (): void {
    $partner = Canary::partnerFor($this->clientA);

    $own = abilityMatrix($partner, $this->recordA, CanaryRecord::class);
    $foreign = abilityMatrix($partner, $this->recordB, CanaryRecord::class);

    expect(array_keys(array_filter($own)))->toEqualCanonicalizing(['view', 'viewAny'])
        ->and(array_keys(array_filter($foreign)))->toBe(['viewAny'])
        ->and($foreign['view'])->toBeFalse();
});

it('denies all twelve abilities to a Partner for a policy that overrides nothing', function (): void {
    $policy = new class extends KokpitPolicy {};
    Gate::policy(CanaryRecord::class, $policy::class);

    $matrix = abilityMatrix(Canary::partnerFor($this->clientA), $this->recordA, CanaryRecord::class);

    expect($matrix)->toHaveCount(12)
        ->and(array_filter($matrix))->toBe([]);
});

it('denies every ability of a policy that overrides nothing to an unknown role as well', function (): void {
    $policy = new class extends KokpitPolicy {};
    Gate::policy(CanaryRecord::class, $policy::class);

    $matrix = abilityMatrix(Canary::userWithRole('auditor', $this->clientA), $this->recordA, CanaryRecord::class);

    expect(array_filter($matrix))->toBe([]);
});

it('denies a Partner every ability of the admin-only policy and admits the Admin', function (): void {
    $policy = new AdminOnlyPolicy;
    Gate::policy(CanaryRecord::class, $policy::class);

    $partner = abilityMatrix(Canary::partnerFor($this->clientA), $this->recordA, CanaryRecord::class);
    $admin = abilityMatrix(Canary::admin(), $this->recordA, CanaryRecord::class);

    expect(array_filter($partner))->toBe([])
        ->and(array_filter($admin))->toHaveCount(12);
});

it('decides by the user the gate is asked about, not by who is signed in', function (): void {
    $this->actingAs(Canary::admin());

    $matrix = abilityMatrix(Canary::partnerFor($this->clientA), $this->recordB, CanaryRecord::class);

    expect($matrix['view'])->toBeFalse();
});
