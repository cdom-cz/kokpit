<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use Filament\Widgets\StatsOverviewWidget;

/**
 * An AdminOnly widget with a mount() of its own. The mount() records that it
 * ran, so a test can prove the access check refuses a Partner before any widget
 * code executes. It is never registered in the panel.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Test fixture proving the access check runs before mount().')]
final class MountProbeWidget extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;

    public static bool $mounted = false;

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
