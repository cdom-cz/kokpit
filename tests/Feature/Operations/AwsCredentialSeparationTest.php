<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

/**
 * The object storage reads S3_*, the SES mailer reads AWS_*, and neither leaks into the other.
 * Credential-like values are assembled at runtime so no line looks like a real value.
 */
function awsFragment(string ...$parts): string
{
    return implode('-', ['example', ...$parts]);
}

/**
 * Runs `php artisan config:show` in a fresh process with the given environment.
 *
 * @param  array<string, string>  $environment
 */
function configShow(string $key, array $environment): string
{
    $process = new Process(
        [PHP_BINARY, 'artisan', 'config:show', $key],
        base_path(),
        ['APP_ENV' => 'testing', 'S3_ACCESS_KEY_ID' => '', 'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', ...$environment],
    );
    $process->run();

    return $process->getOutput().$process->getErrorOutput();
}

it('resolves the SES mailer to a bounded client with the configured credentials and region', function (): void {
    $key = awsFragment('ses', 'access');
    $secret = awsFragment('ses', 'secret');

    config(['services.ses' => ['key' => $key, 'secret' => $secret, 'region' => 'eu-central-1']]);
    Mail::purge('ses');

    $transport = Mail::mailer('ses')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(SesTransport::class);

    /** @var SesTransport $transport */
    $client = $transport->ses();

    expect($client->getCredentials()->wait()->getAccessKeyId())->toBe($key)
        ->and($client->getRegion())->toBe('eu-central-1');

    Mail::purge('ses');
});

it('bounds the SES client by the same timeout as the SMTP mailer', function (): void {
    $timeout = config('mail.mailers.smtp.timeout');

    expect($timeout)->toBeInt()->toBeGreaterThan(0)
        ->and(config('mail.mailers.ses.http.timeout'))->toBe($timeout)
        ->and(config('mail.mailers.ses.http.connect_timeout'))->toBe($timeout)
        ->and(config('mail.mailers.ses.retries'))->toBe(1);
});

it('feeds the object storage from the S3 keys only', function (): void {
    $storageKey = awsFragment('storage', 'access');
    $awsKey = awsFragment('aws', 'access');
    $awsRegion = 'ap-southeast-2';

    $output = configShow('filesystems.disks.s3', [
        'S3_ACCESS_KEY_ID' => $storageKey,
        'AWS_ACCESS_KEY_ID' => $awsKey,
        'AWS_DEFAULT_REGION' => $awsRegion,
    ]);

    expect($output)->toContain($storageKey)
        ->and($output)->toContain('us-east-1')
        ->and($output)->not->toContain($awsKey)
        ->and($output)->not->toContain($awsRegion);
});

it('feeds SES from the AWS keys only and lets AWS_SES_REGION win', function (): void {
    $storageKey = awsFragment('storage', 'access');
    $awsKey = awsFragment('aws', 'access');
    $awsRegion = 'ap-southeast-2';
    $sesRegion = 'eu-west-3';

    $environment = [
        'S3_ACCESS_KEY_ID' => $storageKey,
        'AWS_ACCESS_KEY_ID' => $awsKey,
        'AWS_DEFAULT_REGION' => $awsRegion,
    ];

    $output = configShow('services.ses', $environment);

    expect($output)->toContain($awsKey)
        ->and($output)->toContain($awsRegion)
        ->and($output)->not->toContain($storageKey);

    $withSesRegion = configShow('services.ses', [...$environment, 'AWS_SES_REGION' => $sesRegion]);

    expect($withSesRegion)->toContain($sesRegion)
        ->and($withSesRegion)->not->toContain($awsRegion)
        ->and($withSesRegion)->not->toContain($storageKey);
});
