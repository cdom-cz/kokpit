<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Widgets\StatsOverviewWidget;

/**
 * An AdminOnly widget whose own canView() was overridden to always pass.
 * Filament's built-in access gate then admits every user, so only the
 * access-rule boot hook of the trait can still refuse a Partner before
 * mount(). Never registered in the panel.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Test fixture isolating the access-rule boot hook.')]
final class VisibleOverrideWidget extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;

    public static bool $mounted = false;

    public static function canView(): bool
    {
        return true;
    }

    public function mount(): void
    {
        self::$mounted = true;
    }

    /**
     * @return array<never>
     */
    protected function getStats(): array
    {
        return [];
    }
}
