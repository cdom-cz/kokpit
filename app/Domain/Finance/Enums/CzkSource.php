<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where the CZK figure of a transaction came from (D-13).
 *
 * The values equal the `transactions_czk_source_check` constraint. Identity is a CZK transaction (the figure
 * is the amount), Cnb is a conversion at the CNB list of the transaction date with its snapshot, Manual is a
 * figure the Admin typed and which no recomputation overwrites.
 */
enum CzkSource: string implements HasLabel
{
    case Identity = 'identity';
    case Cnb = 'cnb';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return __('enums.czk_source.'.$this->value);
    }
}
