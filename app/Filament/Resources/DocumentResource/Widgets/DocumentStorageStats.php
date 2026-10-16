<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Widgets;

use App\Domain\Documents\Queries\StorageTotals;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Support\DocumentColumns;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The two tiles above the central listing: how many files are stored and how much space they take (D-11).
 *
 * The tiles describe the whole storage, not the rows the listing currently shows: the filters never reach them,
 * and the files of archived owners count, because archiving deletes no stored object (D-21). Both tiles say so.
 *
 * Registered as a header widget of the listing page and not discovered at panel level, so the dashboard's widget
 * set stays what the dashboard declares.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The volume of stored files across all clients is an operational view for the Admin; a Partner is refused the page and this widget.')]
final class DocumentStorageStats extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;

    protected static bool $isLazy = false;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $totals = app(StorageTotals::class)->overall();
        $note = __('documents.totals.includes_archived');

        return [
            Stat::make(__('documents.totals.files'), (string) $totals['count'])->description($note),
            Stat::make(__('documents.totals.size'), DocumentColumns::humanSize($totals['bytes']))->description($note),
        ];
    }
}
