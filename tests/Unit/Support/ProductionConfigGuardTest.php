<?php

declare(strict_types=1);

use App\Support\ProductionConfigGuard;
use Illuminate\Config\Repository;

/**
 * Credential-like fixtures are assembled at runtime so no single line looks like a real value.
 */
function guardFragment(string ...$parts): string
{
    return implode('-', ['example', ...$parts]);
}

/**
 * A valid production shape; every storage and mail value can be overridden by name.
 *
 * @param  array<string, mixed>|null  $mailers  null keeps the default smtp and ses entries
 */
function guardConfig(
    bool $requireAdminTwoFactor,
    bool $canaryHarness = false,
    mixed $activityLog = true,
    mixed $queueDefault = 'redis',
    mixed $mailDefault = 'smtp',
    mixed $appUrl = 'https://kokpit.example.com',
    mixed $cacheDefault = 'redis',
    mixed $filesystemDefault = 's3',
    mixed $filamentDisk = 's3',
    mixed $mediaDisk = 's3',
    mixed $conversionsDisk = null,
    mixed $livewireDisk = null,
    mixed $s3Key = 'valid',
    mixed $s3Secret = 'valid',
    mixed $s3Bucket = 'valid',
    mixed $sesKey = 'valid',
    mixed $sesSecret = 'valid',
    ?array $mailers = null,
): Repository {
    return new Repository([
        'kokpit' => [
            'require_admin_two_factor' => $requireAdminTwoFactor,
            'canary_harness' => $canaryHarness,
        ],
        'activitylog' => ['enabled' => $activityLog],
        'queue' => ['default' => $queueDefault],
        'mail' => [
            'default' => $mailDefault,
            'mailers' => $mailers ?? [
                'smtp' => ['transport' => 'smtp'],
                'ses' => ['transport' => 'ses'],
            ],
        ],
        'services' => ['ses' => [
            'key' => $sesKey === 'valid' ? guardFragment('ses', 'key') : $sesKey,
            'secret' => $sesSecret === 'valid' ? guardFragment('ses', 'secret') : $sesSecret,
        ]],
        'app' => ['url' => $appUrl],
        'cache' => ['default' => $cacheDefault],
        'filesystems' => [
            'default' => $filesystemDefault,
            'disks' => ['s3' => [
                'key' => $s3Key === 'valid' ? guardFragment('storage', 'key') : $s3Key,
                'secret' => $s3Secret === 'valid' ? guardFragment('storage', 'secret') : $s3Secret,
                'bucket' => $s3Bucket === 'valid' ? guardFragment('storage', 'bucket') : $s3Bucket,
            ]],
        ],
        'filament' => ['default_filesystem_disk' => $filamentDisk],
        'media-library' => ['disk_name' => $mediaDisk, 'conversions_disk_name' => $conversionsDisk],
        'livewire' => ['temporary_file_upload' => ['disk' => $livewireDisk]],
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
    $config = guardConfig(true);
    $kokpit = $config->get('kokpit');
    unset($kokpit['canary_harness']);
    $config->set('kokpit', $kokpit);

    ProductionConfigGuard::check(true, $config);

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

// --- Storage: every stored file lives on the s3 disk -------------------------------------------

it('throws in production when the default disk is not s3', function (mixed $disk): void {
    ProductionConfigGuard::check(true, guardConfig(true, filesystemDefault: $disk));
})->with(['local', 'public', '', null, 'S3'])->throws(RuntimeException::class, 'FILESYSTEM_DISK');

it('throws in production when the Filament default disk is not s3', function (mixed $disk): void {
    ProductionConfigGuard::check(true, guardConfig(true, filamentDisk: $disk));
})->with(['local', 'public', '', null, 'S3'])->throws(RuntimeException::class, 'FILESYSTEM_DISK');

it('throws in production when the media library disk is not s3', function (mixed $disk): void {
    ProductionConfigGuard::check(true, guardConfig(true, mediaDisk: $disk));
})->with(['public', 'local', null])->throws(RuntimeException::class, 'MEDIA_DISK');

it('throws in production when the media conversions disk is public', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, conversionsDisk: 'public'));
})->throws(RuntimeException::class, 'MEDIA_DISK');

it('allows production with the media conversions disk unset or s3', function (mixed $disk): void {
    ProductionConfigGuard::check(true, guardConfig(true, conversionsDisk: $disk));

    expect(true)->toBeTrue();
})->with([null, 's3']);

it('throws in production when the Livewire temporary upload disk is local', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, livewireDisk: 'local'));
})->throws(RuntimeException::class, 'LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK');

it('allows production with the Livewire temporary upload disk unset, empty or s3', function (mixed $disk): void {
    ProductionConfigGuard::check(true, guardConfig(true, livewireDisk: $disk));

    expect(true)->toBeTrue();
})->with([null, '', 's3']);

it('throws in production when the s3 key pair or bucket is missing', function (string $setting, mixed $value): void {
    ProductionConfigGuard::check(true, guardConfig(true, ...[$setting => $value]));
})->with([
    'key missing' => ['s3Key', null],
    'key empty' => ['s3Key', ''],
    'key not a string' => ['s3Key', 5],
    'secret missing' => ['s3Secret', null],
    'secret empty' => ['s3Secret', ''],
    'bucket missing' => ['s3Bucket', null],
    'bucket empty' => ['s3Bucket', ''],
    'bucket not a string' => ['s3Bucket', ['x']],
])->throws(RuntimeException::class, 'S3_ACCESS_KEY_ID');

it('names every S3 variable in the refusal for a missing key pair', function (): void {
    try {
        ProductionConfigGuard::check(true, guardConfig(true, s3Key: null));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY', 'S3_BUCKET');

        return;
    }

    $this->fail('The guard did not refuse.');
});

it('allows every bad storage value outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(
        true,
        filesystemDefault: 'local',
        filamentDisk: 'public',
        mediaDisk: 'public',
        conversionsDisk: 'public',
        livewireDisk: 'local',
        s3Key: null,
        s3Secret: '',
        s3Bucket: null,
    ));

    expect(true)->toBeTrue();
});

it('lets the earlier refusals win over the storage refusals', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, cacheDefault: 'array', filesystemDefault: 'local'));
})->throws(RuntimeException::class, 'CACHE_STORE');

// --- Mail: the SES transport needs the AWS key pair --------------------------------------------

it('throws in production when the SES mailer has no AWS key pair', function (mixed $key, mixed $secret): void {
    ProductionConfigGuard::check(true, guardConfig(true, mailDefault: 'ses', sesKey: $key, sesSecret: $secret));
})->with([
    'key missing' => [null, 'valid'],
    'key empty' => ['', 'valid'],
    'secret missing' => ['valid', null],
    'secret empty' => ['valid', ''],
    'secret not a string' => ['valid', 7],
])->throws(RuntimeException::class, 'AWS_ACCESS_KEY_ID');

it('names both AWS variables in the SES refusal', function (): void {
    try {
        ProductionConfigGuard::check(true, guardConfig(true, mailDefault: 'ses', sesKey: null));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY');

        return;
    }

    $this->fail('The guard did not refuse.');
});

it('refuses the ses-v2 transport without the AWS key pair', function (): void {
    ProductionConfigGuard::check(true, guardConfig(
        true,
        mailDefault: 'ses-v2',
        sesKey: null,
        mailers: ['ses-v2' => ['transport' => 'ses-v2']],
    ));
})->throws(RuntimeException::class, 'AWS_ACCESS_KEY_ID');

it('refuses a failover or roundrobin mailer whose member uses SES without the AWS key pair', function (string $transport): void {
    ProductionConfigGuard::check(true, guardConfig(
        true,
        mailDefault: 'chain',
        sesSecret: null,
        mailers: [
            'smtp' => ['transport' => 'smtp'],
            'ses' => ['transport' => 'ses'],
            'chain' => ['transport' => $transport, 'mailers' => ['smtp', 'ses']],
        ],
    ));
})->with(['failover', 'roundrobin'])->throws(RuntimeException::class, 'AWS_SECRET_ACCESS_KEY');

it('allows smtp without any SES keys', function (): void {
    ProductionConfigGuard::check(true, guardConfig(true, mailDefault: 'smtp', sesKey: null, sesSecret: null));

    expect(true)->toBeTrue();
});

it('allows a failover mailer without SES members and without SES keys', function (): void {
    ProductionConfigGuard::check(true, guardConfig(
        true,
        mailDefault: 'chain',
        sesKey: null,
        sesSecret: null,
        mailers: [
            'smtp' => ['transport' => 'smtp'],
            'chain' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
        ],
    ));

    expect(true)->toBeTrue();
});

it('allows SES without the key pair outside production', function (): void {
    ProductionConfigGuard::check(false, guardConfig(true, mailDefault: 'ses', sesKey: null, sesSecret: ''));

    expect(true)->toBeTrue();
});

it('never prints a configured credential in any refusal', function (): void {
    $fragments = [
        guardFragment('storage', 'key'),
        guardFragment('storage', 'secret'),
        guardFragment('storage', 'bucket'),
        guardFragment('ses', 'key'),
        guardFragment('ses', 'secret'),
    ];

    $bad = [
        guardConfig(true, filesystemDefault: 'local'),
        guardConfig(true, mediaDisk: 'public'),
        guardConfig(true, livewireDisk: 'local'),
        guardConfig(true, s3Secret: ''),
        guardConfig(true, mailDefault: 'ses', sesSecret: ''),
    ];

    foreach ($bad as $config) {
        try {
            ProductionConfigGuard::check(true, $config);
            $this->fail('The guard did not refuse.');
        } catch (RuntimeException $e) {
            foreach ($fragments as $fragment) {
                expect($e->getMessage())->not->toContain($fragment);
            }
        }
    }
});
