<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ProductionConfigGuard;
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
    }
}
