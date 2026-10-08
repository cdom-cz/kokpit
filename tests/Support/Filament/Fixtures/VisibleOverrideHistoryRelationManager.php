<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\RelationManagers\ActivityHistoryRelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * A history relation manager declared AdminOnly whose own visibility check was
 * overridden to always pass. Filament's built-in access gate then admits every
 * user, so only the access-rule boot hook of the trait can still refuse a
 * Partner before mount(). Never registered in the panel.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Test fixture isolating the access-rule boot hook.')]
final class VisibleOverrideHistoryRelationManager extends ActivityHistoryRelationManager
{
    public static bool $mounted = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function mount(): void
    {
        self::$mounted = true;

        parent::mount();
    }
}
