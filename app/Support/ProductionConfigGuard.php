<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Refuses to boot with a configuration that is only acceptable in local
 * development and tests (D-06, D-04). Called from AppServiceProvider::boot().
 *
 * It also checks the storage and mail credentials: every stored file must live on the
 * s3 disk with its own S3_* key pair, and an SES mailer needs the AWS_* key pair.
 * Messages name the env key to fix and never print a configured value.
 */
final class ProductionConfigGuard
{
    public static function check(bool $isProduction, Repository $config): void
    {
        if (! $isProduction) {
            return;
        }

        if ($config->get('kokpit.require_admin_two_factor') !== true) {
            throw new RuntimeException(
                'Refusing to boot in production: KOKPIT_REQUIRE_ADMIN_2FA must be true. '
                .'Switching Admin two-factor authentication off is allowed for local development and tests only.',
            );
        }

        // Anything but a clean false counts as on: the harness exposes test-only panel routes.
        if (! in_array($config->get('kokpit.canary_harness'), [false, null], true)) {
            throw new RuntimeException(
                'Refusing to boot in production: KOKPIT_CANARY_HARNESS must be false. '
                .'The canary harness registers test-only panel surfaces and is allowed in the test suite only.',
            );
        }

        // A switched-off audit trail is silent: anything but a clean true counts as off.
        if ($config->get('activitylog.enabled') !== true) {
            throw new RuntimeException(
                'Refusing to boot in production: ACTIVITYLOG_ENABLED must be true. '
                .'The audit trail may not be switched off in production.',
            );
        }

        // The sync queue runs jobs inline with no worker and no retries, so a failing job would be silent.
        if ($config->get('queue.default') !== 'redis') {
            throw new RuntimeException(
                'Refusing to boot in production: QUEUE_CONNECTION must be redis. '
                .'The sync queue runs jobs inline without a worker, retries or failed-job alerts and is allowed for local development and tests only.',
            );
        }

        // The log and array transports accept every mail without an error: the Admin alert would only be logged or dropped, silently.
        if (! is_string($config->get('mail.default')) || in_array($config->get('mail.default'), ['', 'log', 'array'], true)) {
            throw new RuntimeException(
                'Refusing to boot in production: MAIL_MAILER must be a real mail transport. '
                .'The log and array transports only write the Admin alert mail to the log or drop it, and nobody would notice.',
            );
        }

        // Alert links are built in the worker and the scheduler, where there is no request to take the host from.
        if (! self::isPublicHttpsUrl($config->get('app.url'))) {
            throw new RuntimeException(
                'Refusing to boot in production: APP_URL must be the public https URL of the instance. '
                .'It is set per project (not in zerops.yml) and the worker and the scheduler need it too: mail and alert links are built from it.',
            );
        }

        // onOneServer takes its lock in the cache store, and the heartbeats and the failed-job alert throttle live there too.
        if ($config->get('cache.default') !== 'redis') {
            throw new RuntimeException(
                'Refusing to boot in production: CACHE_STORE must be redis. '
                .'The scheduler runs on every container and onOneServer takes its lock in the cache store; the heartbeats and the failed-job alert throttle need the shared cache too. '
                .'A per-container store (array, file) would run every scheduled task once per container.',
            );
        }

        // Container filesystems are replaced on every deploy and are not shared between containers.
        if ($config->get('filesystems.default') !== 's3' || $config->get('filament.default_filesystem_disk') !== 's3') {
            throw new RuntimeException(
                'Refusing to boot in production: FILESYSTEM_DISK must be s3. '
                .'Container filesystems are replaced on every deploy and are not shared between containers, so a file on a local disk is lost. '
                .'Filament reads FILESYSTEM_DISK as well.',
            );
        }

        if ($config->get('media-library.disk_name') !== 's3' || ! in_array($config->get('media-library.conversions_disk_name'), [null, 's3'], true)) {
            throw new RuntimeException(
                'Refusing to boot in production: MEDIA_DISK must be s3 (MEDIA_CONVERSIONS_DISK unset or s3). '
                .'The public disk is a container-local directory served without authorisation, and documents and invoice PDFs belong in the private bucket.',
            );
        }

        // Livewire falls back to the default disk on an empty value.
        if (! in_array($config->get('livewire.temporary_file_upload.disk'), [null, '', 's3'], true)) {
            throw new RuntimeException(
                'Refusing to boot in production: LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK must be unset or s3. '
                .'An upload parked on one container is missing when the next request reaches another container.',
            );
        }

        if (
            ! self::isNonEmptyString($config->get('filesystems.disks.s3.key'))
            || ! self::isNonEmptyString($config->get('filesystems.disks.s3.secret'))
            || ! self::isNonEmptyString($config->get('filesystems.disks.s3.bucket'))
        ) {
            throw new RuntimeException(
                'Refusing to boot in production: S3_ACCESS_KEY_ID, S3_SECRET_ACCESS_KEY and S3_BUCKET must be set. '
                .'Without its own key pair the AWS SDK falls back to the AWS_* credentials meant for SES.',
            );
        }

        if (
            self::usesSes($config)
            && (! self::isNonEmptyString($config->get('services.ses.key')) || ! self::isNonEmptyString($config->get('services.ses.secret')))
        ) {
            throw new RuntimeException(
                'Refusing to boot in production: AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set for the SES mailer. '
                .'The Admin alert mail would otherwise fail only at send time.',
            );
        }
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * True when the default mailer, or any member of a failover or roundrobin default mailer, sends through SES.
     */
    private static function usesSes(Repository $config): bool
    {
        $default = $config->get('mail.default');

        if (! is_string($default)) {
            return false;
        }

        $names = [$default];
        $mailer = $config->get("mail.mailers.{$default}");

        if (is_array($mailer) && in_array($mailer['transport'] ?? null, ['failover', 'roundrobin'], true) && is_array($mailer['mailers'] ?? null)) {
            foreach ($mailer['mailers'] as $member) {
                if (is_string($member)) {
                    $names[] = $member;
                }
            }
        }

        foreach ($names as $name) {
            $transport = $config->get("mail.mailers.{$name}.transport");

            if (in_array($transport, ['ses', 'ses-v2'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function isPublicHttpsUrl(mixed $url): bool
    {
        if (! is_string($url)) {
            return false;
        }

        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '::1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
            return false;
        }

        return ! str_starts_with($host, '127.');
    }
}
