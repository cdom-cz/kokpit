<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Concerns\InKnowledgeGroup;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use App\Providers\LocalisationServiceProvider;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The Admin screens of the general knowledge articles: the list with the archived switch and the view screen that
 * renders the body through the one renderer (KB-02, KB-03, D-06, D-13).
 *
 * This resource lists only articles of the kind General; incidents and retrospectives get their own resources in a
 * later phase. Archiving is a soft delete, so an archived article stays reachable on its view URL. There is no
 * force-delete, no bulk action and no table search here.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Knowledge articles are internal notes of the Admin; client sharing arrives in Phase 23.')]
final class KnowledgeArticleResource extends Resource
{
    use EnforcesResourceAccessRule;
    use InKnowledgeGroup;

    protected static ?string $model = KnowledgeArticle::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('knowledge.articles.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedBookOpen;
    }

    public static function getModelLabel(): string
    {
        return __('knowledge.articles.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('knowledge.articles.plural_model_label');
    }

    /**
     * Archived articles stay reachable: the archived switch decides what the list shows. Only the general kind
     * belongs to this resource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->where('kind', ArticleKind::General->value);
    }

    /**
     * An archived article opens by its URL.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->where('kind', ArticleKind::General->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The body is large and private: the list never loads it. The archived switch is applied here and not in
            // the filter, because a filter callback runs only while the filter is on, and the default is the other way.
            ->modifyQueryUsing(static function (Builder $query, HasTable $livewire, bool $isResolvingRecord): Builder {
                $query->select(['id', 'kind', 'title', 'version_no', 'edited_at', 'sensitive', 'deleted_at']);

                $showArchived = (bool) data_get($livewire->getTableFilterState('show_archived'), 'isActive', false);

                return $showArchived || $isResolvingRecord ? $query : $query->whereNull('deleted_at');
            })
            ->columns([
                TextColumn::make('title')
                    ->label(__('knowledge.columns.title'))
                    ->weight(FontWeight::SemiBold)
                    ->lineClamp(1)
                    ->tooltip(static fn (string $state): string => $state)
                    ->color(static fn (KnowledgeArticle $record): ?string => $record->trashed() ? 'gray' : null),
                TextColumn::make('archived')
                    ->label('')
                    ->state(static fn (KnowledgeArticle $record): ?string => $record->trashed() ? __('knowledge.badges.archived') : null)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('version_no')
                    ->label(__('knowledge.columns.version'))
                    ->alignEnd(),
                TextColumn::make('edited_at')
                    ->label(__('knowledge.columns.edited_at'))
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('sensitive')
                    ->label(__('knowledge.columns.sensitive'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->trueColor('warning')
                    ->falseIcon(false)
                    ->tooltip(static fn (bool $state): ?string => $state ? __('knowledge.columns.sensitive_tooltip') : null),
            ])
            ->filters([
                Filter::make('show_archived')
                    ->label(__('knowledge.filters.show_archived'))
                    ->toggle(),
            ])
            ->recordUrl(static fn (KnowledgeArticle $record): string => self::getUrl('view', ['record' => $record]))
            ->defaultSort('edited_at', 'desc')
            ->emptyStateHeading(static fn (HasTable $livewire): string => data_get($livewire->getTableFilterState('show_archived'), 'isActive', false)
                ? __('knowledge.empty.archived_heading')
                : __('knowledge.empty.list_heading'))
            ->emptyStateDescription(static fn (HasTable $livewire): ?string => data_get($livewire->getTableFilterState('show_archived'), 'isActive', false)
                ? null
                : __('knowledge.empty.list_description'));
    }

    /**
     * The read-only screen of one article: the meta line, then the body rendered by the one renderer.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.knowledge.article-meta')
                ->columnSpanFull()
                ->viewData(static fn (KnowledgeArticle $record): array => [
                    'version' => $record->version_no,
                    'editedAt' => $record->edited_at->setTimezone('Europe/Prague')->format(LocalisationServiceProvider::DATE_TIME_FORMAT),
                    'sensitive' => $record->sensitive,
                    'archived' => $record->trashed(),
                ]),
            Section::make()
                ->columnSpanFull()
                ->schema([
                    View::make('filament.knowledge.prose')
                        ->viewData(static fn (KnowledgeArticle $record): array => [
                            'html' => app(KnowledgeMarkdown::class)->render((string) $record->body_md),
                            'emptyText' => __('knowledge.empty.body'),
                            'emptyActionUrl' => null,
                            'emptyActionLabel' => null,
                        ]),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeArticles::route('/'),
            'view' => ViewKnowledgeArticle::route('/{record}'),
        ];
    }
}
