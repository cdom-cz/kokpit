<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveResult;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\KnowledgeArticleResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The create screen of a general knowledge article (KB-01): title, Markdown body with the write and preview tabs and
 * the sensitive toggle. The page never writes the model; the one write path is {@see SaveArticle::create()}, and the
 * Admin lands on the view screen of version 1.
 */
final class CreateKnowledgeArticle extends CreateRecord
{
    use RethrowsDomainValidation;

    protected static string $resource = KnowledgeArticleResource::class;

    /**
     * There is no "create and create another": after a save the Admin reads the article.
     */
    protected static bool $canCreateAnother = false;

    /**
     * The tab of the editor, written by the tab buttons (`$set('activeTab', key)`); every opening of a tab is one
     * server roundtrip that carries the unsaved editor text.
     */
    public string $activeTab = 'write';

    /**
     * What the last save did; the notification reads it in the same request.
     */
    private ?SaveResult $saveResult = null;

    /**
     * A Partner is refused with 403 before the form is built.
     */
    public function mount(): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        parent::mount();
    }

    public function getTitle(): string
    {
        return __('knowledge.actions.create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        $result = $this->withFormErrors(
            fn (): SaveResult => app(SaveArticle::class)->create(
                $actor,
                ArticleInput::from($data['title'] ?? null, $data['body'] ?? null, $data['sensitive'] ?? false),
            ),
        );

        $this->saveResult = $result;

        return $result->article;
    }

    protected function getCreatedNotification(): ?Notification
    {
        $key = $this->saveResult?->markedSensitive === true ? 'saved_sensitive' : 'saved';

        return Notification::make()
            ->success()
            ->title(trans()->string('knowledge.notifications.'.$key, ['version' => 1]));
    }

    protected function getRedirectUrl(): string
    {
        return KnowledgeArticleResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('knowledge.actions.create_submit'));
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label(__('knowledge.actions.cancel_create'))
            ->url(KnowledgeArticleResource::getUrl('index'))
            ->color('gray');
    }
}
