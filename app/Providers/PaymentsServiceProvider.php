<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payments\Stripe\StripeApiGateway;
use App\Domain\Payments\Stripe\StripeGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the payments domain. The Stripe gateway interface resolves to the SDK implementation (D-18); the test
 * suite replaces the binding with the fake of tests/Support, so no test reaches Stripe. The two public routes of the
 * phase are limited per visitor (R11-08-5): the pay page, which anyone may guess at, and the webhook, which Stripe
 * retries on a 429 and therefore gets a ceiling far above its real traffic. Later payments plans add their listeners here.
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeGateway::class, StripeApiGateway::class);
    }

    public function boot(): void
    {
        // Used by the route middleware `throttle:pay-page` and `throttle:stripe-webhook` of routes/public.php.
        RateLimiter::for('pay-page', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
        RateLimiter::for('stripe-webhook', fn (Request $request): Limit => Limit::perMinute(600)->by((string) $request->ip()));
    }
}
