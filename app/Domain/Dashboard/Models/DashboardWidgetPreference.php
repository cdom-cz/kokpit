<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Models;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Shared\Auth\NotPartnerScoped;
use App\Domain\Shared\Auth\OwnerScope;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;

/**
 * Whether one user keeps one dashboard card collapsed. A user without a row sees the card expanded.
 *
 * Written only through SetDashboardWidgetCollapsed and read only through CollapsedWidgets. Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $user_id
 * @property DashboardWidget $widget_key
 * @property bool $collapsed
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[NotPartnerScoped(reason: 'A per-user dashboard layout preference with no client data; the OwnerScope limits every role, a Partner included, to the own rows, which is stricter than the client scope.')]
final class DashboardWidgetPreference extends KokpitModel
{
    protected $table = 'dashboard_widget_preferences';

    protected static function booted(): void
    {
        self::addGlobalScope(new OwnerScope);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'widget_key' => DashboardWidget::class,
            'collapsed' => 'boolean',
        ];
    }
}
