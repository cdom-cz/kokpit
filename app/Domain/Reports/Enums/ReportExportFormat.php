<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The file format of a report export.
 *
 * The values equal the `report_exports_format_check` constraint of the table and are the file extensions
 * of the stored file and of the download name.
 */
enum ReportExportFormat: string implements HasLabel
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function extension(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return __('enums.report_export_format.'.$this->value);
    }
}
