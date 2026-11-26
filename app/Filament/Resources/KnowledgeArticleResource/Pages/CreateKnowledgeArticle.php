<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArticleClassification;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveResult;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Projects\Models\Project;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\GuardsSecrets;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\RefusesFailedSaves;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\SecretGuardForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The create screen of a general knowledge article (KB-01): title, Markdown body with the write and preview tabs and
 * the sensitive toggle. The page never writes the model; the one write path is {@see SaveArticle::create()}, and the
 * Admin lands on the view screen of version 1. A refusal of the secret guard keeps every typed character and shows the
 * callouts of {@see GuardsSecrets}; an Ambiguous-only refusal can be saved anyway for the exact body that was refused.
 * A write the database refuses creates nothing, keeps the typed text and says so in a persistent notice
 * ({@see RefusesFailedSaves}).
 */
final class CreateKnowledgeArticle extends CreateRecord implements SecretGuardForm
{
    use GuardsSecrets;
    use RefusesFailedSaves;
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

        $this->prefillClassification();
    }

    /**
     * Fills the folder, the client and the project from the query string of the links that open this screen (the list's
     * "Nový článek" and the "Přidat článek" of the client and project tabs, D-07). The query string can be tampered
     * with, so a value is taken only when it is a UUID of an existing folder, an existing client that is not archived,
     * or a selectable project of that client; a project without a valid client is dropped; anything else is ignored
     * without a message. The values are never trusted later: the save validates the classification again
     * (ArticleClassification and SaveArticle). It runs once, on opening, and never again.
     */
    private function prefillClassification(): void
    {
        $prefill = [];

        $folder = $this->uuidOrNull(request()->query('folder'));

        if ($folder !== null && KnowledgeFolder::query()->whereKey($folder)->exists()) {
            $prefill['folder_id'] = $folder;
        }

        $client = $this->uuidOrNull(request()->query('client'));

        if ($client !== null && Client::query()->whereKey($client)->exists()) {
            $prefill['client_id'] = $client;

            $project = $this->uuidOrNull(request()->query('project'));

            if ($project !== null && Project::query()->selectable()->whereKey($project)->where('projects.client_id', $client)->exists()) {
                $prefill['project_id'] = $project;
            }
        }

        if ($prefill !== []) {
            $this->form->fill([...$this->data, ...$prefill]);
        }
    }

    /**
     * The value when it is a UUID string, in the lowercase spelling of the stored keys; otherwise null (an array, an
     * empty string and a malformed id all end here).
     */
    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
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

        try {
            $result = $this->runGuarded(fn (): SaveResult => $this->withFormErrors(
                fn (): SaveResult => app(SaveArticle::class)->create(
                    $actor,
                    // The classification is built inside the closure, so a field error of it lands under its field.
                    ArticleInput::from(
                        $data['title'] ?? null,
                        $data['body'] ?? null,
                        $data['sensitive'] ?? false,
                        classification: ArticleClassification::from($data['folder_id'] ?? null, $data['client_id'] ?? null, $data['project_id'] ?? null, $data['tags'] ?? []),
                    ),
                    acknowledge: $this->acknowledgeThisSave,
                ),
            ));
        } catch (ArticleWriteFailed $failed) {
            // The database refused the write: nothing was created, the typed text stays (D-09).
            $this->refuseWriteFailure($failed);
        }

        $this->saveResult = $result;

        return $result->article;
    }

    protected function getCreatedNotification(): Notification
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
