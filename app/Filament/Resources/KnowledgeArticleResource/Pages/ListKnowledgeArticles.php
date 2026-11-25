<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Filament\Resources\KnowledgeArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * The list of the general knowledge articles. The header button opens the create screen (KB-01); the empty state of
 * the table offers the same action.
 */
final class ListKnowledgeArticles extends ListRecords
{
    protected static string $resource = KnowledgeArticleResource::class;

    /**
     * A Partner is refused with 403 before anything is read: the deny-all scope of the article would answer 404 or
     * an empty list first.
     */
    public function mount(): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        parent::mount();
    }

    public function getTitle(): string
    {
        return __('knowledge.articles.navigation_label');
    }

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('knowledge.actions.create'))
                ->icon(Heroicon::OutlinedPlus),
        ];
    }
}
