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
    }
}
