<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ProductionConfigGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin two-factor enforcement may be off for local development and tests only (D-06).
        ProductionConfigGuard::check($this->app->isProduction(), config());

        // The invitation link (US-02): ten requests a minute per IP, against token guessing and
        // account-creation spam. Used by the route middleware `throttle:invitation`.
        RateLimiter::for('invitation', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
    }
}
