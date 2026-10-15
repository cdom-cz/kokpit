<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportExportResource\Pages;

use App\Domain\Reports\Models\ReportExport;
use App\Filament\Resources\ReportExportResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The list of the signed-in Admin's report exports (UI-SPEC Surface C2): a heading, the retention line
 * and the table. No header action: exports are started from the report page.
 */
final class ListReportExports extends ListRecords
{
    protected static string $resource = ReportExportResource::class;

    public function getTitle(): string
    {
        return __('kokpit.exports.navigation');
    }

    public function getSubheading(): string
    {
        return __('kokpit.exports.subheading', ['days' => ReportExport::ttlDays()]);
    }
}
