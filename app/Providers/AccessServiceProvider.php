<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the data-layer access rules: the request-scoped Partner context and the
 * policy registrations of the models that carry no policy attribute (D-02).
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(), not singleton() or a plain binding: the system flag must
        // survive between resolutions of one request and reset for the next
        // request and for every queue job.
        $this->app->scoped(PartnerContext::class);
    }

    public function boot(): void
    {
        //
    }
}
