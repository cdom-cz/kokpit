<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Providers\LocalisationServiceProvider;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The versions of one article, newest first (VER-02, D-08, D-10): every content-changing save left one immutable
 * snapshot, and this table lists them with their author, time and markers. It is distinct from the activity history
 * relation manager, which lists metadata events.
 *
 * The table never loads a body: the select names every column except `body_md`. A body is read only for the one
 * version shown or the two versions compared. There is no bulk action, no create, edit or delete action and no search.
 * The restore and redaction actions are added by later plans into an overflow group.
 *
 * A Partner is refused with 403 before the article is looked up: the deny-all scope of the article would answer 404
 * first.
 */
final class ListArticleVersions extends ManageRelatedRecords
{
    protected static string $resource = KnowledgeArticleResource::class;

    protected static string $relationship = 'versions';

    public function mount(int|string $record): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        parent::mount($record);
    }

    public static function getNavigationLabel(): string
    {
        return __('knowledge.versions.navigation');
    }

    public function getBreadcrumb(): string
    {
        return __('knowledge.versions.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedClock;
    }

    /**
     * Verze stays the active item of the sub-navigation on the version view and on the compare page.
     *
     * @return array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [
            static::getRouteName(),
            CompareArticleVersions::getRouteName(),
            ViewArticleVersion::getRouteName(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();
        assert($record instanceof KnowledgeArticle);

        return __('knowledge.versions.title', ['title' => $record->title]);
    }

    public function table(Table $table): Table
    {
        $versionLabel = static fn (int $number): string => trans()->string('knowledge.versions.version_label', ['n' => $number]);

        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                // The relation carries an ascending order of its own; the newest version comes first here.
                ->reorder()
                ->orderByDesc('version_no')
                // Every column but the body: a list never loads the large private text.
                ->select(['id', 'article_id', 'version_no', 'title', 'author_id', 'created_at', 'restored_from_version_no'])
                ->with('author:id,name')
                ->withExists('redactions'))
            ->columns([
                TextColumn::make('version_no')
                    ->label(__('knowledge.versions.columns.version'))
                    ->formatStateUsing(static fn (int $state): string => $versionLabel($state))
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('current')
                    ->label('')
                    ->state(fn (KnowledgeArticleVersion $record): ?string => $record->version_no === $this->currentVersionNo()
                        ? trans()->string('knowledge.versions.badges.current')
                        : null)
                    ->badge()
                    ->color('success'),
                TextColumn::make('title')
                    ->label(__('knowledge.columns.title'))
                    ->lineClamp(1)
                    ->tooltip(static fn (string $state): string => $state),
                TextColumn::make('author.name')
                    ->label(__('knowledge.versions.columns.author')),
                TextColumn::make('created_at')
                    ->label(__('knowledge.versions.columns.created_at'))
                    ->dateTime(LocalisationServiceProvider::DATE_TIME_FORMAT, 'Europe/Prague'),
                TextColumn::make('markers')
                    ->label(__('knowledge.versions.columns.markers'))
                    ->state(static function (KnowledgeArticleVersion $record): array {
                        $markers = [];

                        if ($record->restored_from_version_no !== null) {
                            $markers[] = trans()->string('knowledge.versions.badges.restored_from', ['n' => $record->restored_from_version_no]);
                        }

                        if ((bool) $record->getAttribute('redactions_exists')) {
                            $markers[] = trans()->string('knowledge.versions.badges.redacted');
                        }

                        return $markers;
                    })
                    ->badge()
                    ->color(static fn (string $state): string => $state === trans()->string('knowledge.versions.badges.redacted') ? 'warning' : 'gray')
                    ->icon(static fn (string $state): Heroicon => $state === trans()->string('knowledge.versions.badges.redacted')
                        ? Heroicon::OutlinedEyeSlash
                        : Heroicon::OutlinedArrowUturnLeft),
            ])
            ->recordUrl(fn (KnowledgeArticleVersion $record): string => $this->versionUrl($record->version_no))
            ->recordActions([
                $this->iconAction('view', 'knowledge.versions.actions.view', Heroicon::OutlinedEye)
                    ->url(fn (KnowledgeArticleVersion $record): string => $this->versionUrl($record->version_no)),
                $this->iconAction('compare_previous', 'knowledge.versions.actions.compare_previous', Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (KnowledgeArticleVersion $record): string => $this->compareUrl($record->version_no - 1, $record->version_no))
                    ->hidden(static fn (KnowledgeArticleVersion $record): bool => $record->version_no <= 1),
            ])
            ->paginated([25])
            ->defaultPaginationPageOption(25);
    }

    /**
     * An icon button of a row: the tooltip and the explicit aria-label carry the same copy, because an icon alone
     * names nothing to assistive technology.
     */
    private function iconAction(string $name, string $labelKey, Heroicon $icon): Action
    {
        $label = trans()->string($labelKey);

        return Action::make($name)
            ->label($label)
            ->tooltip($label)
            ->icon($icon)
            ->iconButton()
            ->color('gray')
            ->extraAttributes(['aria-label' => $label]);
    }

    private function currentVersionNo(): int
    {
        $article = $this->getRecord();
        assert($article instanceof KnowledgeArticle);

        return $article->version_no;
    }

    private function versionUrl(int $number): string
    {
        return KnowledgeArticleResource::getUrl('version', ['record' => $this->getRecord(), 'version' => $number]);
    }

    private function compareUrl(int $from, int $to): string
    {
        return KnowledgeArticleResource::getUrl('compare', ['record' => $this->getRecord(), 'from' => $from, 'to' => $to]);
    }
}
