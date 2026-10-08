<?php

declare(strict_types=1);

use App\Support\ProductionConfigGuard;
use Illuminate\Config\Repository;

function guardConfig(bool $requireAdminTwoFactor, bool $canaryHarness = false, mixed $activityLog = true, mixed $queueDefault = 'redis'): Repository
{
    return new Repository([
        'kokpit' => [
            'require_admin_two_factor' => $requireAdminTwoFactor,
            'canary_harness' => $canaryHarness,
        ],
        'activitylog' => ['enabled' => $activityLog],
        'queue' => ['default' => $queueDefault],
    ]);
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
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true], 'activitylog' => ['enabled' => true], 'queue' => ['default' => 'redis']]));

    expect(true)->toBeTrue();
});

it('refuses a canary harness that is not strictly false, even a truthy string', function (): void {
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true, 'canary_harness' => 'yes'], 'activitylog' => ['enabled' => true], 'queue' => ['default' => 'redis']]));
})->throws(RuntimeException::class, 'KOKPIT_CANARY_HARNESS');

it('throws in production when the activity log is switched off', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, activityLog: false));
})->throws(RuntimeException::class, 'ACTIVITYLOG_ENABLED');

it('allows production with the activity log on', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, activityLog: true));

    expect(true)->toBeTrue();
});

it('allows the activity log off outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(true, activityLog: false));

    expect(true)->toBeTrue();
});

it('treats a missing activity log setting as off in production', function (): void {
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true], 'queue' => ['default' => 'redis']]));
})->throws(RuntimeException::class, 'ACTIVITYLOG_ENABLED');

it('refuses an activity log setting that is not strictly true, even a truthy string', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, activityLog: 'yes'));
})->throws(RuntimeException::class, 'ACTIVITYLOG_ENABLED');

it('throws in production when the queue connection is sync', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, queueDefault: 'sync'));
})->throws(RuntimeException::class, 'QUEUE_CONNECTION');

it('allows production with the redis queue connection', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, queueDefault: 'redis'));

    expect(true)->toBeTrue();
});

it('allows the sync queue connection outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(true, queueDefault: 'sync'));

    expect(true)->toBeTrue();
});

it('treats a missing queue setting as not redis in production', function (): void {
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true], 'activitylog' => ['enabled' => true]]));
})->throws(RuntimeException::class, 'QUEUE_CONNECTION');

it('refuses a queue connection that is only similar to redis', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, queueDefault: 'Redis'));
})->throws(RuntimeException::class, 'QUEUE_CONNECTION');

it('still refuses a switched-off activity log when the queue is redis', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, activityLog: false, queueDefault: 'redis'));
})->throws(RuntimeException::class, 'ACTIVITYLOG_ENABLED');
