<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Documents\Models\Document;
use App\Domain\Shared\Database\CzechCollation;
use App\Providers\LocalisationServiceProvider;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * The table columns of the Admin's documents surfaces (D-02): the "Soubory" tab on a client, project and task,
 * and the screens that come after it, build their list from here so the columns cannot drift.
 *
 * The Admin sees every column, including who uploaded a file and whether the client sees it. The Partner
 * tabs (plan 09-08) have their own, shorter builder next to this one and never show the uploader, the flag
 * or the client; the name list below is the one a test pins.
 */
final class DocumentColumns
{
    /** @var list<string> */
    public const array ADMIN_COLUMN_NAMES = ['name', 'owner', 'size_bytes', 'uploader.name', 'created_at', 'visible_to_client'];

    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    /**
     * @return list<Column>
     */
    public static function admin(bool $showOwner): array
    {
        $columns = [
            TextColumn::make('name')
                ->label(__('documents.columns.name'))
                ->description(static fn (Document $record): ?string => $record->description)
                ->wrap()
                ->searchable(['name', 'description'])
                ->sortable(query: static fn (Builder $query, string $direction): Builder => self::orderByName($query, $direction)),
        ];

        return [
            ...$columns,
            TextColumn::make('size_bytes')
                ->label(__('documents.columns.size'))
                ->formatStateUsing(static fn (int $state): string => self::humanSize($state))
                ->sortable(),
            TextColumn::make('uploader.name')
                ->label(__('documents.columns.uploaded_by')),
            TextColumn::make('created_at')
                ->label(__('documents.columns.created_at'))
                ->dateTime(LocalisationServiceProvider::DATE_TIME_FORMAT, self::DISPLAY_TIMEZONE)
                ->sortable(),
            IconColumn::make('visible_to_client')
                ->label(__('documents.columns.visible_to_client'))
                ->boolean(),
        ];
    }

    /**
     * A file size for people: binary units (1 KB is 1024 bytes), one decimal at most and a Czech decimal comma,
     * for example "1,5 MB". Whole numbers carry no decimal ("2 MB"); sizes below 1 KB are plain bytes.
     */
    public static function humanSize(int $bytes): string
    {
        $bytes = max(0, $bytes);

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        $value = round($value, 1);

        // 1048575 bytes rounds to 1024 KB: show it as the next unit instead.
        if ($value >= 1024 && $unit < count($units) - 1) {
            $value = round($value / 1024, 1);
            $unit++;
        }

        $text = rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');

        return $text.' '.$units[$unit];
    }

    /**
     * Czech alphabetical order of the display name (CONTRIBUTING "Ordering"), the id as tie-breaker.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private static function orderByName(Builder $query, string $direction): Builder
    {
        return CzechCollation::orderBy($query, $query->qualifyColumn('name'), $direction)->orderBy($query->qualifyColumn('id'));
    }
}
