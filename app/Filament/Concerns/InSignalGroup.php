<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use UnitEnum;

/**
 * Puts a page into the navigation group "Signal", which has its own section of the menu.
 */
trait InSignalGroup
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('kokpit.signal.navigation_group');
    }
}
