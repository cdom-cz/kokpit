<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Tags\TagType;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\KnowledgeArticleResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * The tab "Znalosti" of the Admin project page (ORG-04, D-07): the general knowledge articles linked to this project
 * only. An article linked just to the client of the project is on the client tab, not here. Admin only.
 *
 * The list never loads the Markdown body. Archived articles stay hidden until the archived switch is on; the switch
 * is applied in the query scope, like the list of the article resource. Only the general kind is listed. The tab is a
 * read-only listing with one link action, so it stays visible on the read-only view page.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Internal knowledge articles of the Admin; a Partner never sees this tab before client sharing in Phase 23.')]
final class ProjectKnowledgeRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'knowledgeArticles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('knowledge.links.tab.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(static function (Builder $query, HasTable $livewire, bool $isResolvingRecord): Builder {
                $query->withoutGlobalScopes([SoftDeletingScope::class])
                    ->where('knowledge_articles.kind', ArticleKind::General->value)
                    ->select(['id', 'kind', 'title', 'version_no', 'edited_at', 'sensitive', 'deleted_at', 'client_id', 'project_id', 'folder_id'])
                    ->with('tags');

                $showArchived = (bool) data_get($livewire->getTableFilterState('show_archived'), 'isActive', false);

                return $showArchived || $isResolvingRecord ? $query : $query->whereNull('knowledge_articles.deleted_at');
            })
            ->columns([
                TextColumn::make('title')
                    ->label(__('knowledge.columns.title'))
                    ->weight(FontWeight::SemiBold)
                    ->lineClamp(1)
                    ->tooltip(static fn (string $state): string => $state)
                    ->color(static fn (KnowledgeArticle $record): ?string => $record->trashed() ? 'gray' : null)
                    ->url(static fn (KnowledgeArticle $record): string => KnowledgeArticleResource::getUrl('view', ['record' => $record])),
                TextColumn::make('version_no')
                    ->label(__('knowledge.columns.version'))
                    ->alignEnd(),
                TextColumn::make('edited_at')
                    ->label(__('knowledge.columns.edited_at'))
                    ->dateTime()
                    ->sortable(),
                SpatieTagsColumn::make('tags')
                    ->label(__('knowledge.columns.tags'))
                    ->type(TagType::Knowledge->value)
                    ->limitList(3),
                IconColumn::make('sensitive')
                    ->label(__('knowledge.columns.sensitive'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->trueColor('warning')
                    ->falseIcon(false)
                    ->tooltip(static fn (bool $state): ?string => $state ? trans()->string('knowledge.columns.sensitive_tooltip') : null),
            ])
            ->filters([
                Filter::make('show_archived')
                    ->label(__('knowledge.filters.show_archived'))
                    ->toggle(),
            ])
            ->headerActions([$this->addArticleAction()])
            ->defaultSort('edited_at', 'desc')
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon(Heroicon::OutlinedBookOpen)
            ->emptyStateHeading(__('knowledge.links.tab.empty_heading'))
            ->emptyStateDescription(__('knowledge.links.tab.empty_project'))
            ->emptyStateActions([$this->addArticleAction()]);
    }

    /**
     * The link to the create screen of an article with the project of this page and its client in the query string.
     * A plain link action, so the read-only view page shows it too.
     */
    private function addArticleAction(): Action
    {
        return Action::make('addArticle')
            ->label(__('knowledge.links.tab.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->url(function (): string {
                /** @var Project $project */
                $project = $this->getOwnerRecord();

                return KnowledgeArticleResource::getUrl('create', ['client' => $project->client_id, 'project' => $project->getKey()]);
            });
    }
}
