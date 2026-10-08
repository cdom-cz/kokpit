<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Filament\Pages\Page;

/**
 * An AdminOnly page whose own canAccess() was overridden to always pass. The
 * override replaces the trait method, so only the access-rule boot hook of the
 * trait can still refuse a Partner before mount(). Never registered in the panel.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Test fixture isolating the page access-rule boot hook.')]
final class AccessOverrideMountProbePage extends Page
{
    use EnforcesPageAccessRule;

    public static bool $mounted = false;

    public static function canAccess(): bool
    {
        return true;
    }

    public function mount(): void
    {
        self::$mounted = true;
    }
}
