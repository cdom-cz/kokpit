<?php

declare(strict_types=1);

namespace App\Domain\Projects\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The lifecycle state of a project (D-16).
 *
 * The values equal the `projects_status_check` constraint of the projects
 * table; a test pins the two lists against each other.
 */
enum ProjectStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case ToClarify = 'to_clarify';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case ReadyToRelease = 'ready_to_release';
    case Done = 'done';

    public function getLabel(): string
    {
        return __('enums.project_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::ToClarify => 'warning',
            self::InProgress => 'info',
            self::InReview => 'primary',
            self::ReadyToRelease => 'success',
            self::Done => 'success',
        };
    }
}
