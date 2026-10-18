<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Str;

/**
 * Sets the two Stripe secrets for a test from fragments assembled at runtime, so no test file contains a
 * Stripe-shaped value (R11-01-6, Pitfall 14). The values are random per call; a test reads them back from
 * the config when it needs to assert that a secret did not leak.
 */
final class StripeTestConfig
{
    public static function configure(): void
    {
        config([
            'services.stripe.secret' => implode('_', ['sk', 'test', Str::random(32)]),
            'services.stripe.webhook_secret' => implode('', ['whsec', '_', Str::random(32)]),
        ]);
    }

    public static function clear(): void
    {
        config(['services.stripe.secret' => null, 'services.stripe.webhook_secret' => null]);
    }
}
