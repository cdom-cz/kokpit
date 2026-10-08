<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Refuses to boot with a configuration that is only acceptable in local
 * development and tests (D-06, D-04). Called from AppServiceProvider::boot().
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
