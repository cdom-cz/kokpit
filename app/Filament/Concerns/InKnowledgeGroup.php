<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use UnitEnum;

/**
 * Puts a resource into the navigation group "Znalosti", which sits after the work entries and before "Signal".
 */
trait InKnowledgeGroup
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('knowledge.navigation_group');
    }
}
