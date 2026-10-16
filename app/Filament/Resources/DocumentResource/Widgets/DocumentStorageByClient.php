<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Widgets;

use App\Domain\Documents\Queries\StorageTotals;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Support\DocumentColumns;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * "Podle klientů" below the tiles of the central listing: for every client that has files, the number of files and
 * their size, and one last row for the files that belong to no client (D-11).
 *
 * That last row is worded "Nepřiřazené ke klientovi" because it holds the unattached files and also the receipts of
 * transactions without a client (research A17). A file counts for the client in its denormalized `client_id`, so
 * the files of a client's projects, tasks and comments roll up to it. Like the tiles, the rows describe the whole
 * storage: the listing filters never reach them, and an archived client keeps its row with the "archivovaný" label
 * because archiving deletes no stored object (D-21).
 */
#[AccessRule(Audience::AdminOnly, reason: 'The volume of stored files per client is an operational view for the Admin; a Partner is refused the page and this widget.')]
final class DocumentStorageByClient extends TableWidget
{
    use EnforcesWidgetAccessRule;

    /** The key of the row of the files without a client. */
    private const string NO_CLIENT_KEY = 'no_client';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('documents.totals.by_client_heading'))
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('name')
                    ->label(__('documents.totals.columns.client'))
                    ->state(static fn (array $record): string => $record['client_id'] === null ? (string) __('documents.totals.no_client') : $record['name'])
                    ->description(static fn (array $record): ?string => $record['archived'] ? (string) __('documents.totals.archived_client') : null),
                TextColumn::make('count')
                    ->label(__('documents.totals.columns.count'))
                    ->state(static fn (array $record): string => (string) $record['count'])
                    ->alignment(Alignment::End)
                    ->extraCellAttributes(['style' => 'font-variant-numeric: tabular-nums;']),
                TextColumn::make('bytes')
                    ->label(__('documents.totals.columns.size'))
                    ->state(static fn (array $record): string => DocumentColumns::humanSize($record['bytes']))
                    ->alignment(Alignment::End)
                    ->extraCellAttributes(['style' => 'font-variant-numeric: tabular-nums;']),
            ])
            ->paginated(false);
    }

    /**
     * The rows keyed by client id, the row without a client under its own key.
     *
     * @return array<string, array{client_id: ?string, name: string, archived: bool, count: int, bytes: int}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (app(StorageTotals::class)->byClient() as $row) {
            $rows[$row['client_id'] ?? self::NO_CLIENT_KEY] = $row;
        }

        return $rows;
    }
}
