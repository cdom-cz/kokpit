<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a change in the activity log came from (D-08).
 *
 * Derived from the execution context on the server, never from input. The
 * activity_log.source column holds exactly these values (CHECK constraint).
 */
enum ActivitySourceLabel: string implements HasLabel
{
    case Web = 'web';
    case Console = 'console';
    case Job = 'job';
    case Webhook = 'webhook';

    public function getLabel(): string
    {
        return __('enums.activity_source_label.'.$this->value);
    }
}
