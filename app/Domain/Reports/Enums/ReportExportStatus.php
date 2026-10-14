<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where a report export stands: waiting for the queue, finished, or failed (D-09).
 *
 * The values equal the `report_exports_status_check` constraint of the table; a test pins the two lists
 * against each other.
 */
enum ReportExportStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('enums.report_export_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): ?Heroicon
    {
        return match ($this) {
            self::Pending, self::Ready => null,
            self::Failed => Heroicon::OutlinedExclamationCircle,
        };
    }
}
