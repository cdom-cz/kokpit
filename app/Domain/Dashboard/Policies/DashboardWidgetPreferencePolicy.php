<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Policies;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\KokpitPolicy;

/**
 * Who may store or read the collapsed state of a dashboard card.
 *
 * The Admin is admitted by `before()`; a guest, a user without a role and a Partner without a client are refused
 * there. A Partner with a client reaches `collapse()` and gets the cards a Partner can see, nothing else.
 */
final class DashboardWidgetPreferencePolicy extends KokpitPolicy
{
    public function collapse(User $user, DashboardWidget $widget): bool
    {
        return $widget->isPartnerWidget();
    }
}
