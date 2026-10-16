<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where the CZK figure of a transaction came from (D-13).
 *
 * The values equal the `transactions_czk_source_check` constraint. Identity is a CZK transaction (the figure
 * is the amount), Cnb is a conversion at the CNB list of the transaction date with its snapshot, Manual is a
 * figure the Admin typed and which no recomputation overwrites, Custom is a conversion at an own rate the
 * caller gave for this one transaction (D-06), stored as the snapshot with the transaction date as the rate date.
 */
enum CzkSource: string implements HasLabel
{
    case Identity = 'identity';
    case Cnb = 'cnb';
    case Manual = 'manual';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return __('enums.czk_source.'.$this->value);
    }
}
