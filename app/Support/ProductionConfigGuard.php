<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Refuses to boot with a configuration that is only acceptable in local
 * development and tests (D-06). Called from AppServiceProvider::boot().
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
    }
}
