<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Billing;

use Filament\Support\Contracts\HasLabel;

/**
 * The level of the inheritance chain that supplied one effective billing value
 * (D-14): the task itself, its parent task, its project or its client.
 */
enum BillingSource: string implements HasLabel
{
    case Task = 'task';
    case ParentTask = 'parent_task';
    case Project = 'project';
    case Client = 'client';

    public function getLabel(): string
    {
        return __('enums.billing_source.'.$this->value);
    }
}
