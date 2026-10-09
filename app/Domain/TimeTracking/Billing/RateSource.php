<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Billing;

use Filament\Support\Contracts\HasLabel;

/**
 * The level that supplied the effective hourly rate of a time entry (TI-08):
 * the task, its parent task, the project, the client, or the global default.
 *
 * It mirrors the billing sources of a task and adds the global default. Its labels
 * live under `kokpit.time.rate_source.*` (UI-SPEC wording); the task page keeps
 * its own `enums.billing_source.*` labels.
 */
enum RateSource: string implements HasLabel
{
    case Task = 'task';
    case ParentTask = 'parent_task';
    case Project = 'project';
    case Client = 'client';
    case Default = 'default';

    public function getLabel(): string
    {
        return __('kokpit.time.rate_source.'.$this->value);
    }
}
