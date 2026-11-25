<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveResult;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\GuardsSecrets;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\SecretGuardForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The create screen of a general knowledge article (KB-01): title, Markdown body with the write and preview tabs and
 * the sensitive toggle. The page never writes the model; the one write path is {@see SaveArticle::create()}, and the
 * Admin lands on the view screen of version 1. A refusal of the secret guard keeps every typed character and shows the
 * callouts of {@see GuardsSecrets}; an Ambiguous-only refusal can be saved anyway for the exact body that was refused.
 */
final class CreateKnowledgeArticle extends CreateRecord implements SecretGuardForm
{
    use GuardsSecrets;
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
     * The browser's own leave warning while the form holds unsaved changes (D-08). It is scoped to the two article
     * form pages: the panel-wide setting stays off. A tab switch changes no form data and a successful save
     * remembers the saved data, so neither raises it.
     */
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        $result = $this->runGuarded(fn (): SaveResult => $this->withFormErrors(
            fn (): SaveResult => app(SaveArticle::class)->create(
                $actor,
                ArticleInput::from($data['title'] ?? null, $data['body'] ?? null, $data['sensitive'] ?? false),
                acknowledge: $this->acknowledgeThisSave,
            ),
        ));

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

    /**
     * The save-anyway button follows the create button; it is offered only for an Ambiguous-only refusal (D-03).
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('saveAnyway')
                ->label(__('knowledge.actions.save_anyway'))
                ->color('warning')
                ->visible(fn (): bool => $this->canSaveAnyway())
                ->action('saveAnyway'),
            $this->getCancelFormAction(),
        ];
    }

    protected function submitGuardedForm(): void
    {
        $this->create();
    }
}
