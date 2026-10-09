<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use Filament\Support\Contracts\HasLabel;

/**
 * How the supplier handles VAT on issued documents.
 *
 * Only NonPayer is supported in this milestone. Payer stays in the enum so the
 * data model does not change when VAT-payer support arrives; the settings form
 * shows it disabled and the settings class refuses it.
 */
enum VatMode: string implements HasLabel
{
    case NonPayer = 'non_payer';
    case Payer = 'payer';

    public function getLabel(): string
    {
        return __('enums.vat_mode.'.$this->value);
    }
}
