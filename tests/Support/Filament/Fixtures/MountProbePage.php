<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Filament\Pages\Page;

/**
 * An AdminOnly page that uses the enforcing trait and has a mount() of its own.
 * The mount() records that it ran, so a test can prove the access check refuses
 * a Partner before any page code executes. It is never registered in the panel.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Test fixture proving the access check runs before mount().')]
final class MountProbePage extends Page
{
    use EnforcesPageAccessRule;

    public static bool $mounted = false;

    public function mount(): void
    {
        self::$mounted = true;
    }
}
