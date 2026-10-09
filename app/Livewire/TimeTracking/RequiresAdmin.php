<?php

declare(strict_types=1);

namespace App\Livewire\TimeTracking;

use App\Domain\Shared\Auth\PartnerContext;

/**
 * Refuses everybody but the Admin on mount and on every later request of a Livewire component.
 *
 * The timer components sit outside the Filament registry, so no #[AccessRule] reaches them, and
 * /livewire/update is a public endpoint: any signed-in user can post a forged component request.
 * Livewire runs the `boot` hook of a trait before `mount()` and before every `hydrate()`, so a
 * forged update by a Partner is refused with 403 before any action method runs and writes nothing.
 * It is the same idea as EnforcesPageAccessRule for pages. The Actions authorize again behind it.
 */
trait RequiresAdmin
{
    public function bootRequiresAdmin(): void
    {
        abort_unless(app(PartnerContext::class)->isAdmin(), 403);
    }
}
