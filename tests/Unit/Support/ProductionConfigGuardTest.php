<?php

declare(strict_types=1);

use App\Support\ProductionConfigGuard;
use Illuminate\Config\Repository;

function guardConfig(bool $requireAdminTwoFactor, bool $canaryHarness = false): Repository
{
    return new Repository(['kokpit' => [
        'require_admin_two_factor' => $requireAdminTwoFactor,
        'canary_harness' => $canaryHarness,
    ]]);
}

it('throws in production when Admin two-factor enforcement is off', function (): void {
    ProductionConfigGuard::check(true, guardConfig(false));
})->throws(RuntimeException::class, 'KOKPIT_REQUIRE_ADMIN_2FA');

it('allows production with enforcement on', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true));

    expect(true)->toBeTrue();
});

it('allows enforcement off outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(false));

    expect(true)->toBeTrue();
});

it('treats a missing setting as off in production', function (): void {
    ProductionConfigGuard::check(true, new Repository([]));
})->throws(RuntimeException::class);

it('throws in production when the canary harness is on', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, canaryHarness: true));
})->throws(RuntimeException::class, 'KOKPIT_CANARY_HARNESS');

it('allows production with the canary harness off', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, canaryHarness: false));

    expect(true)->toBeTrue();
});

it('allows the canary harness outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(true, canaryHarness: true));

    expect(true)->toBeTrue();
});

it('treats a missing canary setting as off in production', function (): void {
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true]]));

    expect(true)->toBeTrue();
});

it('refuses a canary harness that is not strictly false, even a truthy string', function (): void {
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true, 'canary_harness' => 'yes']]));
})->throws(RuntimeException::class, 'KOKPIT_CANARY_HARNESS');
