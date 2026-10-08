<?php

declare(strict_types=1);

namespace App\Domain\Projects\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The priority of a project (D-16).
 *
 * The values equal the `projects_priority_check` constraint of the projects
 * table; a test pins the two lists against each other.
 */
enum ProjectPriority: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return __('enums.project_priority.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }
}
