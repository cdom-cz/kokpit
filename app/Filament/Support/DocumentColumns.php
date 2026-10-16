<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Providers\LocalisationServiceProvider;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The table columns of the Admin's documents surfaces (D-02): the "Soubory" tab on a client, project and task,
 * and the screens that come after it, build their list from here so the columns cannot drift.
 *
 * The Admin sees every column, including who uploaded a file and whether the client sees it. The Partner
 * tabs have their own, shorter builder next to this one (`partner()`) and never show the uploader, the flag,
 * the owner or the client; the name lists above are the ones a test pins. Both builders share the column
 * definitions below, so the two cannot drift apart.
 */
final class DocumentColumns
{
    /** @var list<string> */
    public const array PARTNER_COLUMN_NAMES = ['name', 'description', 'size_bytes', 'created_at'];

    /** @var list<string> */
    public const array ADMIN_COLUMN_NAMES = ['name', 'owner', 'size_bytes', 'uploader.name', 'created_at', 'visible_to_client'];

    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    /**
     * @return list<Column>
     */
    public static function admin(bool $showOwner): array
    {
        $columns = [
            self::nameColumn()
                ->description(static fn (Document $record): ?string => $record->description)
                ->searchable(['name', 'description']),
        ];

        if ($showOwner) {
            // The query behind the list must call withOwners(), or each row loads its owner on its own.
            $columns[] = TextColumn::make('owner')
                ->label(__('documents.columns.owner'))
                ->state(static fn (Document $record): string => self::ownerLabel($record))
                ->description(static fn (Document $record): ?string => self::ownerName($record));
        }

        return [
            ...$columns,
            self::sizeColumn(),
            TextColumn::make('uploader.name')
                ->label(__('documents.columns.uploaded_by')),
            self::createdAtColumn(),
            IconColumn::make('visible_to_client')
                ->label(__('documents.columns.visible_to_client'))
                ->boolean(),
        ];
    }

    /**
     * The columns of a Partner's "Soubory" tab (D-04): name, description, size and date, exactly
     * {@see self::PARTNER_COLUMN_NAMES}. No uploader account, owner kind, flag or client.
     *
     * @return list<Column>
     */
    public static function partner(): array
    {
        return [
            self::nameColumn()
                ->searchable(),
            TextColumn::make('description')
                ->label(__('documents.columns.description'))
                ->wrap()
                ->searchable()
                ->placeholder('–'),
            self::sizeColumn(),
            self::createdAtColumn(),
        ];
    }

    /**
     * Loads the owner of every row in a fixed number of queries, whatever the owner kinds (a clients, projects,
     * tasks and comments mix), archived owners included: the Admin still sees where a file of an archived
     * project or task hangs. A comment is shown with the reference of its task, so that is loaded with it.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public static function withOwners(Builder $query): Builder
    {
        return $query->with(['owner' => static fn (MorphTo $owner) => $owner
            ->withTrashed()
            ->morphWith([TaskComment::class => ['task' => static fn ($task) => $task->withTrashed()]])]);
    }

    /**
     * What the file hangs on, in words: Klient, Projekt, Úkol, Komentář, Transakce, or "Nepřiřazeno" for a file without an owner.
     */
    public static function ownerLabel(Document $document): string
    {
        $type = $document->owner_type === null ? null : DocumentOwnerType::tryFrom($document->owner_type);

        return $type === null ? __('documents.owner_none') : $type->getLabel();
    }

    /**
     * The name of the owner: the client or project name, the task reference, the reference of the task
     * a comment sits on, or the counterparty of a transaction. Null when there is no owner row to name.
     */
    public static function ownerName(Document $document): ?string
    {
        $owner = $document->owner;

        return match (true) {
            $owner instanceof Client, $owner instanceof Project => $owner->name,
            $owner instanceof Task => $owner->reference,
            $owner instanceof TaskComment => $owner->task?->reference,
            $owner instanceof Transaction => $owner->counterparty,
            default => null,
        };
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

    private static function nameColumn(): TextColumn
    {
        return TextColumn::make('name')
            ->label(__('documents.columns.name'))
            ->wrap()
            ->sortable(query: static fn (Builder $query, string $direction): Builder => self::orderByName($query, $direction));
    }

    private static function sizeColumn(): TextColumn
    {
        return TextColumn::make('size_bytes')
            ->label(__('documents.columns.size'))
            ->formatStateUsing(static fn (int $state): string => self::humanSize($state))
            ->sortable();
    }

    private static function createdAtColumn(): TextColumn
    {
        return TextColumn::make('created_at')
            ->label(__('documents.columns.created_at'))
            ->dateTime(LocalisationServiceProvider::DATE_TIME_FORMAT, self::DISPLAY_TIMEZONE)
            ->sortable();
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
