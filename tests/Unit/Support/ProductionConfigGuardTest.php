<?php

declare(strict_types=1);

use App\Support\ProductionConfigGuard;
use Illuminate\Config\Repository;

function guardConfig(bool $requireAdminTwoFactor): Repository
{
    return new Repository(['kokpit' => ['require_admin_two_factor' => $requireAdminTwoFactor]]);
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
