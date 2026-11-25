<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Actions\SaveResult;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Filament\Concerns\RethrowsDomainValidation;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\GuardsSecrets;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\SecretGuardForm;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * The edit screen of a general knowledge article (KB-02): the same form as the create screen, saved only through
 * {@see SaveArticle::update()} with the version the screen was opened with.
 *
 * `$loadedVersion` and `$loadedSensitive` are locked and set once, in `mount()`. They are never refreshed, not even
 * after a refused save, so pressing "Uložit změny" again after a stale refusal is refused again and a flag set by
 * another save in the meantime is never cleared by a toggle that was already off when the screen opened (D-04 fail
 * closed, D-09). A stale save writes nothing, keeps every typed character and offers the current article in a new
 * tab; there is no overwrite and no merge. A save without changes, or with a change of the sensitive flag alone,
 * writes no version and says so (D-10, VER-01). The secret guard behaves as on the create screen (SEC-01 to SEC-03).
 */
final class EditKnowledgeArticle extends EditRecord implements SecretGuardForm
{
    use GuardsSecrets;
    use RethrowsDomainValidation;

    protected static string $resource = KnowledgeArticleResource::class;

    /**
     * The tab of the editor, written by the tab buttons; every opening of a tab is one server roundtrip that carries
     * the unsaved editor text.
     */
    public string $activeTab = 'write';

    /**
     * The version number the article had when this screen was opened. Locked and written only in `mount()`.
     */
    #[Locked]
    public int $loadedVersion;

    /**
     * Whether the sensitive toggle showed the flag on when this screen was opened. Locked and written only in
     * `mount()`: the manual clear of the flag is derived from it, never from the record read on a later request.
     */
    #[Locked]
    public bool $loadedSensitive;

    /**
     * What the last save did; the notification reads it in the same request.
     */
    private ?SaveResult $saveResult = null;

    /**
     * A Partner is refused with 403 before the record is looked up, and an archived article is read-only: it answers
     * 404 on this URL until it is restored.
     */
    public function mount(int|string $record): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        parent::mount($record);

        $article = $this->getRecord();
        assert($article instanceof KnowledgeArticle);

        abort_if($article->trashed(), 404);

        $this->loadedVersion = $article->version_no;
        $this->loadedSensitive = $article->sensitive;
    }

    /**
     * The editor shows the stored text, not the stored column names: the form holds a title, a body and the toggle.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $article = $this->getRecord();
        assert($article instanceof KnowledgeArticle);

        return [
            'title' => $article->title,
            'body' => $article->body_md,
            'sensitive' => $article->sensitive,
        ];
    }

    #[Unexposed]
    public function getSubheading(): string
    {
        return trans()->string('knowledge.meta.version', ['version' => $this->loadedVersion]);
    }

    /**
     * The history table stays on the view screen.
     *
     * @return array<never>
     */
    #[Unexposed]
    public function getRelationManagers(): array
    {
        return [];
    }

    /**
     * The browser's own leave warning while the form holds unsaved changes (D-08), scoped to the two article form
     * pages: the panel-wide setting stays off.
     */
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            KnowledgeArticleResource::archiveAction()
                ->successRedirectUrl(fn (): string => KnowledgeArticleResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * Saves through the one write path with the version this screen was opened with. The manual clear of the
     * sensitive flag is sent only when the toggle showed the flag on at opening and the Admin turned it off (D-04).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof KnowledgeArticle);

        $actor = auth()->user();
        assert($actor instanceof User);

        $sensitive = (bool) ($data['sensitive'] ?? false);

        try {
            $result = $this->runGuarded(fn (): SaveResult => $this->withFormErrors(
                fn (): SaveResult => app(SaveArticle::class)->update($actor, $record, ArticleInput::from($data['title'] ?? null, $data['body'] ?? null, $sensitive, clearSensitive: $this->loadedSensitive && ! $sensitive), $this->loadedVersion, acknowledge: $this->acknowledgeThisSave),
            ));
        } catch (StaleArticle) {
            // Nothing was written. The form stays as typed, and the starting version stays as it was, so the next
            // press of the save button is refused again (D-09).
            Notification::make()
                ->warning()
                ->persistent()
                ->title(trans()->string('knowledge.notifications.stale_title'))
                ->body(trans()->string('knowledge.notifications.stale_body'))
                ->actions([
                    Action::make('load_current')
                        ->label(trans()->string('knowledge.actions.load_current'))
                        ->url(KnowledgeArticleResource::getUrl('view', ['record' => $record]), shouldOpenInNewTab: true),
                ])
                ->send();

            throw new Halt;
        }

        $this->saveResult = $result;

        return $result->article;
    }

    protected function getSavedNotification(): ?Notification
    {
        $result = $this->saveResult;

        if ($result === null) {
            return null;
        }

        return match ($result->outcome) {
            SaveOutcome::Unchanged => Notification::make()->info()->title(trans()->string('knowledge.notifications.unchanged')),
            SaveOutcome::SensitivityChanged => Notification::make()->info()->title(trans()->string('knowledge.notifications.sensitivity_changed')),
            default => Notification::make()->success()->title(trans()->string(
                'knowledge.notifications.'.($result->markedSensitive ? 'saved_sensitive' : 'saved'),
                ['version' => $result->article->version_no],
            )),
        };
    }

    protected function getRedirectUrl(): string
    {
        return KnowledgeArticleResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('knowledge.actions.save_submit'));
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label(__('knowledge.actions.cancel_edit'))
            ->url(KnowledgeArticleResource::getUrl('view', ['record' => $this->getRecord()]))
            ->color('gray');
    }

    /**
     * The save-anyway button follows the save button; it is offered only for an Ambiguous-only refusal (D-03).
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
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
        $this->save();
    }
}
