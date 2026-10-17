<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How the time of a draft is turned into lines (D-02): one line per project or one line per task, chosen per
 * draft. Inside either choice the time is split further by effective rate, so a line always has one unit price.
 */
enum ItemGrouping: string implements HasLabel
{
    case PerProject = 'project';
    case PerTask = 'task';

    public function getLabel(): string
    {
        return __('enums.item_grouping.'.$this->value);
    }
}
