<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\ReportExportResource\Pages\ListReportExports;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report exports of the signed-in Admin (RE-03, D-09; UI-SPEC Surface C2).
 *
 * A list page only: the rows are created by the report page and built by the queue, and the 7-day prune is the
 * only cleanup, so there is no create, edit, view, delete or bulk action. The query is the owner scope of the
 * model: another Admin's export is not in the list and cannot be reached by a forged record key.
 *
 * The file leaves the private disk only through the row action `download`, which re-reads the row, re-checks
 * that it is finished and unexpired and that the file is still there, and then hands the file to the browser
 * under its server-generated name. There is no public or temporary URL and no route (D-09).
 */
#[AccessRule(Audience::AdminOnly, reason: 'Report exports hold measured time and rates; only the Admin who made an export downloads it.')]
final class ReportExportResource extends Resource
{
    use EnforcesResourceAccessRule;

    /** The time zone every shown instant is converted to. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    /** The date format of the list: day, month, year and time. */
    private const string DATE_FORMAT = 'j. n. Y H:i';

    protected static ?string $model = ReportExport::class;

    protected static ?string $slug = 'report-exports';

    protected static ?int $navigationSort = 43;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.exports.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedArrowDownTray;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.exports.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.exports.plural_label');
    }

    /**
     * Exports are queued from the report page, never through a create page.
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
                    ->label(__('kokpit.exports.columns.created_at'))
                    ->dateTime(self::DATE_FORMAT, self::DISPLAY_TIMEZONE)
                    ->sortable(),
                TextColumn::make('format')
                    ->label(__('kokpit.exports.columns.format'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('summary')
                    ->label(__('kokpit.exports.columns.summary'))
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere;'])
                    // A failed row names the reason class in plain Czech, never the exception text.
                    ->tooltip(static fn (ReportExport $record): ?string => $record->status === ReportExportStatus::Failed
                        ? (string) __('kokpit.exports.failure_reason')
                        : null),
                TextColumn::make('status')
                    ->label(__('kokpit.exports.columns.status'))
                    ->badge(),
                TextColumn::make('expires_at')
                    ->label(__('kokpit.exports.columns.expires_at'))
                    ->state(static fn (ReportExport $record): ?CarbonImmutable => $record->status === ReportExportStatus::Ready ? $record->expires_at : null)
                    ->dateTime(self::DATE_FORMAT, self::DISPLAY_TIMEZONE)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginated([25])
            // Polls only while an export of the owner is still waiting for the queue.
            ->poll(static fn (): ?string => ReportExport::query()->where('status', ReportExportStatus::Pending->value)->exists() ? '10s' : null)
            ->recordActions([
                self::downloadAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArrowDownTray)
            ->emptyStateHeading(__('kokpit.exports.empty.heading'))
            ->emptyStateDescription(__('kokpit.exports.empty.description'));
    }

    /**
     * The row action: shown for a finished, unexpired export only. At click time it re-reads the record
     * through the owner scope by its key and re-checks the whole condition, so an export that expired, was
     * pruned or lost its file between render and click is answered with a toast and no file.
     */
    private static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('kokpit.exports.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(static fn (ReportExport $record): bool => $record->isDownloadable(CarbonImmutable::now()))
            ->action(static function (ReportExport $record): ?StreamedResponse {
                abort_unless(self::canAccess(), 403);

                $export = ReportExport::query()->whereKey($record->getKey())->first();
                $disk = Storage::disk();

                if (
                    $export === null
                    || ! $export->isDownloadable(CarbonImmutable::now())
                    || $export->path === null
                    || ! $disk->exists($export->path)
                ) {
                    Notification::make()->danger()->title(__('kokpit.exports.gone'))->send();

                    return null;
                }

                return $disk->download($export->path, $export->downloadFileName());
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportExports::route('/'),
        ];
    }
}
