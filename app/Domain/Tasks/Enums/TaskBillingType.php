<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a task is billed (D-12): inherited from its project, by the hour, for a
 * fixed price, or not billed at all. Non-billable exists only on tasks; the
 * project billing type stays hourly or fixed price.
 *
 * The values equal the `task_billing_billing_type_check` constraint of the
 * task_billing table.
 */
enum TaskBillingType: string implements HasLabel
{
    case Inherit = 'inherit';
    case Hourly = 'hourly';
    case FixedPrice = 'fixed_price';
    case NonBillable = 'non_billable';

    public function getLabel(): string
    {
        return __('enums.task_billing_type.'.$this->value);
    }
}
