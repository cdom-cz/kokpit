<?php

declare(strict_types=1);

namespace App\Domain\Projects\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a project is billed (D-05): by the hour or for a fixed price.
 *
 * The values equal the `project_billing_billing_type_check` constraint of the
 * project_billing table.
 */
enum BillingType: string implements HasLabel
{
    case Hourly = 'hourly';
    case FixedPrice = 'fixed_price';

    public function getLabel(): string
    {
        return __('enums.billing_type.'.$this->value);
    }
}
