<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentDisposition;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * The actions every documents surface of the Admin shares (D-02): upload, download and preview.
 *
 * Upload hands only the Livewire temporary uploads, the description and (for an owner that can be shared) the
 * flag to AttachDocuments; the owner, the client and the project come from the host's own record, never from
 * the form. Files are not stored by the field itself (`storeFiles(false)`): the temporary object stays where
 * Livewire put it until the Action has checked, copied and removed it.
 *
 * Delete is permanent (D-09): it asks for a confirmation that names the file, and it removes the row, the media
 * and the stored object through DeleteDocument or not at all.
 *
 * Download and preview authorize the file and mint the signed link at click time, then redirect to it. A
 * link is never rendered into the page, because a page that stays open longer than the link lives would
 * hold a dead one, and a copy of the page would hold a live one (D-10, T-09-18).
 */
final class DocumentActions
{
    public static function upload(): Action
    {
        return Action::make('upload')
            ->label(__('documents.actions.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('documents.actions.upload'))
            ->modalSubmitActionLabel(__('documents.actions.upload_submit'))
            ->schema(static fn (Component $livewire): array => self::uploadFields($livewire))
            ->action(static function (array $data, Component $livewire): void {
                $owner = self::owner($livewire);
                $files = is_array($data['files'] ?? null) ? array_values($data['files']) : [];

                try {
                    $documents = app(AttachDocuments::class)->handle(
                        self::user(),
                        $owner,
                        $files,
                        // The Action ignores the flag for an owner that cannot be shared (client files stay hidden).
                        self::isShareable($owner) ? (bool) ($data['visible_to_client'] ?? false) : null,
                        is_string($data['description'] ?? null) ? $data['description'] : null,
                    );
                } catch (ValidationException $e) {
                    throw self::underModal($e);
                }

                Notification::make()
                    ->success()
                    ->title(trans_choice('documents.notifications.uploaded', $documents->count(), ['count' => $documents->count()]))
                    ->send();
            });
    }

    public static function download(): Action
    {
        return Action::make('download')
            ->label(__('documents.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(static function (Document $record, Component $livewire): void {
                Gate::forUser(self::user())->authorize('view', $record);

                $livewire->redirect(DocumentUrl::download($record, self::user()));
            });
    }

    public static function preview(): Action
    {
        return Action::make('preview')
            ->label(__('documents.actions.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(static fn (Document $record): bool => DocumentDisposition::isInline($record))
            ->action(static function (Document $record, Component $livewire): void {
                Gate::forUser(self::user())->authorize('view', $record);

                $livewire->redirect(DocumentUrl::preview($record, self::user()));
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label(__('documents.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('documents.actions.delete_heading'))
            ->modalDescription(static fn (Document $record): string => __('documents.actions.delete_description', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('documents.actions.delete'))
            ->action(static function (Document $record): void {
                if (self::deleteOne($record)) {
                    Notification::make()->success()->title(__('documents.notifications.deleted'))->send();

                    return;
                }

                Notification::make()->danger()->title(__('documents.notifications.delete_failed'))->send();
            });
    }

    /**
     * Deletes one file for the signed-in Admin. A refusal by the policy is not swallowed; any other failure
     * (the stored object cannot be removed, the file was deleted in the meantime) is reported without the
     * file name and answered with false, so nothing is half done.
     */
    private static function deleteOne(Document $document): bool
    {
        try {
            app(DeleteDocument::class)->handle(self::user(), $document);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report(new RuntimeException('A document could not be deleted ('.$e::class.').', previous: $e));

            return false;
        }

        return true;
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function uploadFields(Component $livewire): array
    {
        $owner = self::owner($livewire);
        $shareable = self::isShareable($owner);

        $maxKb = (int) config('kokpit.documents.max_size_kb');
        $maxFiles = (int) config('kokpit.documents.max_files_per_upload');

        return [
            FileUpload::make('files')
                ->label(__('documents.fields.files'))
                ->helperText(__('documents.hints.files', ['count' => $maxFiles, 'max' => (int) ($maxKb / 1024)]).($shareable ? '' : ' '.__('documents.hints.client_hidden')))
                ->multiple()
                ->storeFiles(false)
                ->maxSize($maxKb)
                ->maxFiles($maxFiles)
                ->required(),
            Textarea::make('description')
                ->label(__('documents.fields.description'))
                ->maxLength(2000)
                ->rows(3),
            Toggle::make('visible_to_client')
                ->label(__('documents.fields.visible_to_client'))
                ->helperText(__('documents.hints.visible_to_client'))
                ->default($shareable)
                ->visible($shareable),
        ];
    }

    /**
     * The record the files hang on: the owner of the relation manager that hosts the action.
     */
    private static function owner(Component $livewire): ?Model
    {
        return $livewire instanceof RelationManager ? $livewire->getOwnerRecord() : null;
    }

    /**
     * Whether a file on this owner can be shared with the client: the project, the task and the comment,
     * not the client itself (D-03, D-20).
     */
    private static function isShareable(?Model $owner): bool
    {
        $type = match (true) {
            $owner instanceof Project => DocumentOwnerType::Project,
            $owner instanceof Task => DocumentOwnerType::Task,
            $owner instanceof TaskComment => DocumentOwnerType::TaskComment,
            default => null,
        };

        return $type?->isShareable() ?? false;
    }

    private static function user(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path, so the
     * messages are re-keyed to land next to the field (the same mapping as the Kontakty tab).
     */
    private static function underModal(ValidationException $e): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            $mapped["mountedActions.0.data.{$key}"] = $messages;
        }

        return ValidationException::withMessages($mapped);
    }
}
