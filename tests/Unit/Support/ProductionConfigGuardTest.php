<?php

declare(strict_types=1);

use App\Support\ProductionConfigGuard;
use Illuminate\Config\Repository;

function guardConfig(bool $requireAdminTwoFactor, bool $canaryHarness = false, mixed $activityLog = true, mixed $queueDefault = 'redis', mixed $mailDefault = 'smtp', mixed $appUrl = 'https://kokpit.example.com', mixed $cacheDefault = 'redis'): Repository
{
    return new Repository([
        'kokpit' => [
            'require_admin_two_factor' => $requireAdminTwoFactor,
            'canary_harness' => $canaryHarness,
        ],
        'activitylog' => ['enabled' => $activityLog],
        'queue' => ['default' => $queueDefault],
        'mail' => ['default' => $mailDefault],
        'app' => ['url' => $appUrl],
        'cache' => ['default' => $cacheDefault],
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
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true], 'activitylog' => ['enabled' => true], 'queue' => ['default' => 'redis'], 'mail' => ['default' => 'smtp'], 'app' => ['url' => 'https://kokpit.example.com'], 'cache' => ['default' => 'redis']]));

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

it('throws in production when the mailer only logs or drops mail', function (mixed $mailer): void {
    ProductionConfigGuard::check(true, guardConfig(true, mailDefault: $mailer));
})->with(['log', 'array', '', null, 5])->throws(RuntimeException::class, 'MAIL_MAILER');

it('allows production with a real mail transport', function (string $mailer): void {
    ProductionConfigGuard::check(true, guardConfig(true, mailDefault: $mailer));

    expect(true)->toBeTrue();
})->with(['smtp', 'ses', 'postmark', 'resend', 'sendmail']);

it('allows the log and array mailers outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(true, mailDefault: 'log'));
    ProductionConfigGuard::check(false, guardConfig(true, mailDefault: 'array'));

    expect(true)->toBeTrue();
});

it('treats a missing mail setting as not a real transport in production', function (): void {
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true], 'activitylog' => ['enabled' => true], 'queue' => ['default' => 'redis'], 'app' => ['url' => 'https://kokpit.example.com']]));
})->throws(RuntimeException::class, 'MAIL_MAILER');

it('throws in production when APP_URL is not a public https URL', function (mixed $url): void {
    ProductionConfigGuard::check(true, guardConfig(true, appUrl: $url));
})->with([
    'default localhost' => 'http://localhost',
    'localhost over https' => 'https://localhost',
    'loopback address' => 'https://127.0.0.1',
    'ipv6 loopback' => 'https://[::1]',
    'localhost subdomain' => 'https://kokpit.localhost',
    'test tld' => 'https://kokpit.test',
    'plain http' => 'http://kokpit.example.com',
    'no scheme' => 'kokpit.example.com',
    'empty' => '',
    'null' => null,
])->throws(RuntimeException::class, 'APP_URL');

it('allows production with a public https APP_URL', function (string $url): void {
    ProductionConfigGuard::check(true, guardConfig(true, appUrl: $url));

    expect(true)->toBeTrue();
})->with(['https://kokpit.example.com', 'https://kokpit.example.com/', 'https://crm.example.org:8443']);

it('allows a localhost APP_URL outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(true, appUrl: 'http://localhost'));

    expect(true)->toBeTrue();
});

it('throws in production when the cache store is not the shared redis store', function (mixed $store): void {
    ProductionConfigGuard::check(true, guardConfig(true, cacheDefault: $store));
})->with(['array', 'file', 'database', 'Redis', '', null])->throws(RuntimeException::class, 'CACHE_STORE');

it('allows production with the redis cache store', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, cacheDefault: 'redis'));

    expect(true)->toBeTrue();
});

it('allows the array cache store outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(true, cacheDefault: 'array'));

    expect(true)->toBeTrue();
});

it('treats a missing cache setting as not redis in production', function (): void {
    ProductionConfigGuard::check(true, new Repository(['kokpit' => ['require_admin_two_factor' => true], 'activitylog' => ['enabled' => true], 'queue' => ['default' => 'redis'], 'mail' => ['default' => 'smtp'], 'app' => ['url' => 'https://kokpit.example.com']]));
})->throws(RuntimeException::class, 'CACHE_STORE');
