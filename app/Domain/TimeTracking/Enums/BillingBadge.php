<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Enums;

use App\Domain\TimeTracking\Models\TimeEntry;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * How a time entry reads on the screens: still to bill, billed (locked), or not
 * billable at all (UI-SPEC "Billing state labels").
 *
 * It is a display state, not a storage state. The stored `BillingState` has two
 * values and no label; "not billable" comes from the `billable` flag, and a
 * billed entry is always billable (the database refuses anything else).
 */
enum BillingBadge: string implements HasColor, HasIcon, HasLabel
{
    case Unbilled = 'unbilled';
    case Billed = 'billed';
    case NonBillable = 'non_billable';

    public static function for(TimeEntry $entry): self
    {
        return match (true) {
            $entry->billing_state === BillingState::Billed => self::Billed,
            ! $entry->billable => self::NonBillable,
            default => self::Unbilled,
        };
    }

    public function getLabel(): string
    {
        return __('enums.time_billing_state.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unbilled => 'info',
            self::Billed => 'success',
            self::NonBillable => 'gray',
        };
    }

    /**
     * The lock marks a billed row; the other states carry no icon.
     */
    public function getIcon(): ?Heroicon
    {
        return $this === self::Billed ? Heroicon::OutlinedLockClosed : null;
    }
}
