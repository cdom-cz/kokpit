<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\DocumentArchiveResource\Pages\ListDocumentArchives;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * The ZIP archives of the signed-in Admin (DO-02, D-08; the twin of ReportExportResource).
 *
 * A list page only: the rows are created by the bulk action of the files listing and built by the queue, and the
 * 7-day prune is the only cleanup, so there is no create, edit, view, delete or bulk action. The query is the owner
 * scope of the model: another Admin's archive is not in the list and cannot be reached by a forged record key.
 *
 * The file leaves the private disk only through the signed application route (DO-03, D-10). The row action
 * `download` re-reads the row, re-checks that it is finished and unexpired and that the file is still there, and
 * only then builds the short-lived signed link and redirects to it. The link is never rendered into the page: a
 * ZIP must not be buffered into a Livewire response, and a page left open would hold a dead link.
 */
#[AccessRule(Audience::AdminOnly, reason: 'A ZIP archive bundles files of every client; only the Admin who asked for it downloads it.')]
final class DocumentArchiveResource extends Resource
{
    use EnforcesResourceAccessRule;

    /** The time zone every shown instant is converted to. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    /** The date format of the list: day, month, year and time. */
    private const string DATE_FORMAT = 'j. n. Y H:i';

    protected static ?string $model = DocumentArchive::class;

    protected static ?string $slug = 'document-archives';

    protected static ?int $navigationSort = 56;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('documents.archives.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedArchiveBox;
    }

    public static function getModelLabel(): string
    {
        return __('documents.archives.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documents.archives.plural_label');
    }

    /**
     * Archives are queued from the files listing, never through a create page.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('documents.archives.columns.created_at'))
                    ->dateTime(self::DATE_FORMAT, self::DISPLAY_TIMEZONE)
                    ->sortable(),
                TextColumn::make('summary')
                    ->label(__('documents.archives.columns.summary'))
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
                TextColumn::make('status')
                    ->label(__('documents.archives.columns.status'))
                    ->badge(),
                TextColumn::make('expires_at')
                    ->label(__('documents.archives.columns.expires_at'))
                    ->state(static fn (DocumentArchive $record): ?CarbonImmutable => $record->status === DocumentArchiveStatus::Ready ? $record->expires_at : null)
                    ->dateTime(self::DATE_FORMAT, self::DISPLAY_TIMEZONE)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginated([25])
            // Polls only while an archive of the owner is still waiting for the queue (D-08).
            ->poll(static fn (): ?string => DocumentArchive::query()->where('status', DocumentArchiveStatus::Pending->value)->exists() ? '5s' : null)
            ->recordActions([
                self::downloadAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox)
            ->emptyStateHeading(__('documents.archives.empty.heading'))
            ->emptyStateDescription(__('documents.archives.empty.description'));
    }

    /**
     * The row action: shown for a finished, unexpired archive only. At click time it re-reads the record through
     * the owner scope by its key and re-checks the whole condition, so an archive that expired, was pruned or lost
     * its file between render and click is answered with a toast and no redirect. The route checks all of it once
     * more when the browser follows the link.
     */
    private static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('documents.archives.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(static fn (DocumentArchive $record): bool => $record->isDownloadable(CarbonImmutable::now()))
            ->action(static function (DocumentArchive $record, Component $livewire): void {
                abort_unless(self::canAccess(), 403);

                $user = Auth::user();
                $archive = DocumentArchive::query()->whereKey($record->getKey())->first();

                if (
                    ! $user instanceof User
                    || $archive === null
                    || ! $archive->isDownloadable(CarbonImmutable::now())
                    || $archive->path === null
                    || ! Storage::disk()->exists($archive->path)
                ) {
                    Notification::make()->danger()->title(__('documents.archives.gone'))->send();

                    return;
                }

                $livewire->redirect(DocumentUrl::archive($archive, $user));
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentArchives::route('/'),
        ];
    }
}
