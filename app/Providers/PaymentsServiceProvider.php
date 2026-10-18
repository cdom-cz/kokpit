<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payments\Stripe\StripeApiGateway;
use App\Domain\Payments\Stripe\StripeGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the payments domain. The Stripe gateway interface resolves to the SDK implementation (D-18); the test
 * suite replaces the binding with the fake of tests/Support, so no test reaches Stripe. Later payments plans
 * add their rate limiters and listeners here.
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeGateway::class, StripeApiGateway::class);
    }
}
