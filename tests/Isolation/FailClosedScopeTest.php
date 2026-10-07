<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\PartnerContext;
use Tests\Support\Canary;
use Tests\Support\CanaryRecord;

/*
 * The fail-closed matrix of the Partner scope (D-02) on the test-only tenant
 * model. Two fictional clients each own two rows; every row carries a canary
 * string that must never reach the other client's Partner.
 */

beforeEach(function (): void {
    Canary::createTable();

    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->secretsA = [Canary::canary('A'), Canary::canary('A')];
    $this->secretsB = [Canary::canary('B'), Canary::canary('B')];

    foreach ($this->secretsA as $secret) {
        Canary::record($this->clientA, $secret);
    }

    foreach ($this->secretsB as $secret) {
        $this->rowB = Canary::record($this->clientB, $secret);
    }
});

it('shows a guest no row at all', function (): void {
    expect(CanaryRecord::query()->count())->toBe(0)
        ->and(CanaryRecord::query()->get())->toHaveCount(0);
});

it('shows a Partner without a client no row', function (): void {
    $this->actingAs(Canary::partnerFor(null));

    expect(CanaryRecord::query()->get())->toHaveCount(0);
});

it('shows a user with a client but no role no row', function (): void {
    $this->actingAs(Canary::userWithoutRole($this->clientA));

    expect(CanaryRecord::query()->get())->toHaveCount(0);
});

it('shows a user with an unknown role and a client no row', function (): void {
    $this->actingAs(Canary::userWithRole('auditor', $this->clientA));

    expect(CanaryRecord::query()->get())->toHaveCount(0);
});

it('shows a Partner exactly the rows of their own client and none of the other client', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $secrets = CanaryRecord::query()->pluck('secret')->all();

    expect($secrets)->toHaveCount(2)
        ->and($secrets)->toEqualCanonicalizing($this->secretsA)
        ->and($secrets)->each->not->toStartWith('CANARY_B_');
});

it('does not let a Partner find a row of another client by its id', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(CanaryRecord::query()->find($this->rowB->id))->toBeNull()
        ->and(CanaryRecord::query()->whereKey($this->rowB->id)->exists())->toBeFalse();
});

it('shows two Partner accounts of one client the same rows', function (): void {
    $first = Canary::partnerFor($this->clientA);
    $second = Canary::partnerFor($this->clientA);

    $this->actingAs($first);
    $seenByFirst = CanaryRecord::query()->pluck('secret')->sort()->values()->all();

    $this->actingAs($second);
    $seenBySecond = CanaryRecord::query()->pluck('secret')->sort()->values()->all();

    expect($seenByFirst)->toHaveCount(2)
        ->and($seenBySecond)->toBe($seenByFirst);
});

it('shows the Partner of another client none of the rows of the first client', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientB));

    $secrets = CanaryRecord::query()->pluck('secret')->all();

    expect($secrets)->toEqualCanonicalizing($this->secretsB)
        ->and($secrets)->each->not->toStartWith('CANARY_A_');
});

it('keeps count and exists inside the same constraint', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(CanaryRecord::query()->count())->toBe(2)
        ->and(CanaryRecord::query()->exists())->toBeTrue()
        ->and(CanaryRecord::query()->where('client_id', $this->clientB)->count())->toBe(0)
        ->and(CanaryRecord::query()->where('client_id', $this->clientB)->exists())->toBeFalse();

    $this->actingAs(Canary::partnerFor(null));

    expect(CanaryRecord::query()->count())->toBe(0)
        ->and(CanaryRecord::query()->exists())->toBeFalse();
});

it('keeps the constraint when a Partner adds an or-condition', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    $rows = CanaryRecord::query()
        ->where('client_id', $this->clientA)
        ->orWhere('client_id', $this->clientB)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('client_id')->unique()->all())->toBe([$this->clientA]);
});

it('shows the Admin every row', function (): void {
    $this->actingAs(Canary::admin());

    expect(CanaryRecord::query()->count())->toBe(4);
});

it('shows a system run every row even without a user', function (): void {
    $context = app(PartnerContext::class);

    expect($context->runAsSystem(fn (): int => CanaryRecord::query()->count()))->toBe(4)
        ->and(CanaryRecord::query()->count())->toBe(0);
});

it('sees the system flag set through one resolution in every other resolution of the same request', function (): void {
    app(PartnerContext::class)->runAsSystem(function (): void {
        expect(app(PartnerContext::class)->isSystem())->toBeTrue()
            ->and(CanaryRecord::query()->count())->toBe(4);
    });
});

it('restores the system flag after a callback that throws', function (): void {
    $context = app(PartnerContext::class);

    expect(fn () => $context->runAsSystem(function (): never {
        throw new RuntimeException('work failed');
    }))->toThrow(RuntimeException::class, 'work failed');

    expect($context->isSystem())->toBeFalse()
        ->and(CanaryRecord::query()->count())->toBe(0);
});

it('restores the outer system flag when a nested system run ends', function (): void {
    $context = app(PartnerContext::class);

    $context->runAsSystem(function () use ($context): void {
        $context->runAsSystem(static fn (): null => null);

        expect($context->isSystem())->toBeTrue();
    });

    expect($context->isSystem())->toBeFalse();
});

it('starts every request with the system flag off', function (): void {
    app(PartnerContext::class)->runAsSystem(static fn (): null => null);

    app()->forgetScopedInstances();

    expect(app(PartnerContext::class)->isSystem())->toBeFalse();
});

it('reports a client only for a Partner that has one', function (): void {
    $context = app(PartnerContext::class);

    expect($context->partnerClientId())->toBeNull();

    $this->actingAs(Canary::partnerFor($this->clientA));
    expect($context->partnerClientId())->toBe($this->clientA)
        ->and($context->isAdmin())->toBeFalse();

    $this->actingAs(Canary::partnerFor(null));
    expect($context->partnerClientId())->toBeNull();

    $this->actingAs(Canary::userWithoutRole($this->clientA));
    expect($context->partnerClientId())->toBeNull();

    $this->actingAs(Canary::admin());
    expect($context->partnerClientId())->toBeNull()
        ->and($context->isAdmin())->toBeTrue();
});
