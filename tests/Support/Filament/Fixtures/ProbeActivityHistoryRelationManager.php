<?php

declare(strict_types=1);

namespace Tests\Support\Filament\Fixtures;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\RelationManagers\ActivityHistoryRelationManager;

/**
 * The few lines a later phase writes to attach the history to its record:
 * extend the abstract relation manager and declare the audience. Its mount()
 * records that it ran, so a test can prove a refused Partner never reaches it.
 * Never registered in the panel.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Test fixture: activity history of the activity probe record.')]
final class ProbeActivityHistoryRelationManager extends ActivityHistoryRelationManager
{
    public static bool $mounted = false;

    public function mount(): void
    {
        self::$mounted = true;

        parent::mount();
    }
}
