<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Actions;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Dashboard\Models\DashboardWidgetPreference;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Stores whether the actor keeps a dashboard card collapsed.
 *
 * The state is personal: it is written to the actor's own row only, never to a row the caller names. One upsert on
 * (user_id, widget_key) makes two tabs toggling at once safe without a lock. This Action is the only writer of the
 * table.
 *
 * Not final on purpose: a test binds a subclass that fails, to prove the widget reverts and tells the user.
 */
class SetDashboardWidgetCollapsed
{
    /**
     * @throws AuthorizationException when the actor may not hold state for the card
     */
    public function handle(User $actor, DashboardWidget $widget, bool $collapsed): void
    {
        Gate::forUser($actor)->authorize('collapse', [DashboardWidgetPreference::class, $widget]);

        DashboardWidgetPreference::query()->upsert(
            [['user_id' => $actor->getKey(), 'widget_key' => $widget->value, 'collapsed' => $collapsed]],
            ['user_id', 'widget_key'],
            ['collapsed'],
        );
    }
}
