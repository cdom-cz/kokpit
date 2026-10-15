<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Dashboard\Models\DashboardWidgetPreference;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Reads whether a user keeps a dashboard card collapsed; a user without a row has it expanded.
 */
final class CollapsedWidgets
{
    /**
     * @throws AuthorizationException when the user may not hold state for the card
     */
    public function isCollapsed(User $user, DashboardWidget $widget): bool
    {
        Gate::forUser($user)->authorize('collapse', [DashboardWidgetPreference::class, $widget]);

        return DashboardWidgetPreference::query()
            ->where('user_id', $user->getKey())
            ->where('widget_key', $widget->value)
            ->value('collapsed') === true;
    }
}
