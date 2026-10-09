<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Enums;

/**
 * The storage state of a time entry: still open for billing, or billed.
 *
 * The values equal the `time_entries_state_check` constraint. The enum has no
 * label on purpose: the display state (with the "not billable" third value)
 * is a separate enum of the screens.
 */
enum BillingState: string
{
    case Unbilled = 'unbilled';
    case Billed = 'billed';
}
