<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The own screen of one article: the title as heading, the meta line and the body rendered by the one renderer.
 * Archive, restore and edit buttons are added by later plans.
 */
final class ViewKnowledgeArticle extends ViewRecord
{
    protected static string $resource = KnowledgeArticleResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up: the deny-all scope of the article would answer
     * 404 first.
     */
    public function mount(int|string $record): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        parent::mount($record);
    }

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();
        assert($record instanceof KnowledgeArticle);

        return $record->title;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
