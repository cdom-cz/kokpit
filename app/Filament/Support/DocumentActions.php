<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Actions\RenameDocument;
use App\Domain\Documents\Actions\RequestDocumentArchive;
use App\Domain\Documents\Actions\SetDocumentVisibility;
use App\Domain\Documents\Enums\DocumentOwnerType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentDisposition;
use App\Domain\Documents\Support\DocumentFileName;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
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
 * Rename and the visibility switch (D-02, D-03) go through RenameDocument and SetDocumentVisibility, which own
 * the rules; the actions only collect the input and report the result.
 *
 * Delete is permanent (D-09, D-23): it asks for a confirmation that names the file, and it goes through
 * DeleteDocument, which removes the row and the media row in one transaction and the stored object after the
 * commit (or, when the disk refuses it then, through the queued RemoveDocumentObjects job). The rows are deleted
 * together or not at all; a refused object never undoes a delete the Admin confirmed.
 *
 * Download authorizes the file and mints the signed link at click time, then redirects to it. The preview of
 * an image does the same inside the lightbox: the link is minted when the modal renders, after the `view`
 * check, and exists only while the modal is open. A link is otherwise never rendered into the page, because a
 * page that stays open longer than the link lives would hold a dead one, and a copy of the page would hold a
 * live one (D-10, T-09-18).
 *
 * The one exception, decided by the owner in Phase 17: the inline preview of a non-image file (a PDF) is a real
 * link with `target="_blank"` that a list renders into its HTML, so the file opens in a new tab and no popup
 * blocker intervenes. The link is bound to the viewing user (`u` under the signature), lives for
 * `kokpit.documents.signed_url_ttl_seconds` and is guarded by the unchanged file route (authenticated, signed,
 * Partner-scoped lookup, `view` policy). It is rendered only for files the viewer may see. The accepted
 * consequence: a page left open longer than the lifetime holds an expired link that answers 403 in the new
 * tab until the list renders again. Images, downloads and archives keep the click-time rule.
 */
final class DocumentActions
{
    /** The key of the tick that confirms deleting the PDF of an issued document. */
    private const string CONFIRM_ISSUED_PDF = 'confirmIssuedPdf';

    public static function upload(): Action
    {
        return Action::make('upload')
            ->label(__('documents.actions.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('documents.actions.upload'))
            ->modalSubmitActionLabel(__('documents.actions.upload_submit'))
            ->schema(static fn (Component $livewire): array => self::uploadFields(self::owner($livewire)))
            ->action(static function (array $data, Component $livewire): void {
                self::attach($data, self::owner($livewire));
            });
    }

    /**
     * The upload of the central listing: a file that hangs on nothing (D-01, success criterion 1). There is no
     * owner to take from the page and no visibility switch, because an unattached file is never shared.
     */
    public static function uploadUnattached(): Action
    {
        return Action::make('uploadUnattached')
            ->label(__('documents.actions.upload_unattached'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('documents.actions.upload_unattached'))
            ->modalSubmitActionLabel(__('documents.actions.upload_submit'))
            ->schema(static fn (): array => self::uploadFields(null, unattached: true))
            ->action(static function (array $data): void {
                self::attach($data, null);
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

    /**
     * "Zobrazit" (D-08). An image opens in a single-image lightbox: the signed link is minted when the modal
     * renders, after the `view` check, and exists only inside the open modal. A PDF is a real link that opens
     * in a new browser tab (`target="_blank"`), so no popup blocker applies; its link is minted when the row
     * renders and is the one link a list puts into its HTML (see the class docblock).
     */
    public static function preview(): Action
    {
        return self::lightboxModal(Action::make('preview')
            ->label(__('documents.actions.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(static fn (Document $record): bool => DocumentDisposition::isInline($record))
            ->url(
                static fn (Document $record): ?string => DocumentDisposition::isInlineImage($record) ? null : DocumentUrl::preview($record, self::user()),
                shouldOpenInNewTab: true,
            )
            ->modalHeading(static fn (Document $record): Htmlable => self::lightboxHeading($record->name))
            ->modalContent(static function (Document $record): View {
                Gate::forUser(self::user())->authorize('view', $record);
                abort_unless(DocumentDisposition::isInlineImage($record), 404);

                return self::lightboxView($record, self::user());
            }));
    }

    /**
     * The modal frame every lightbox shares (D-08): 5xl wide, no submit, one "Zavřít" button. ESC, the close X
     * and a click on the backdrop close it (Filament defaults); there is no previous/next, zoom or thumbnail strip.
     */
    public static function lightboxModal(Action $action): Action
    {
        return $action
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('documents.actions.close'));
    }

    /**
     * The modal heading of a lightbox: the file name on one line, cut with an ellipsis when it is too long.
     */
    public static function lightboxHeading(string $name): Htmlable
    {
        return new HtmlString('<span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'.e($name).'</span>');
    }

    /**
     * The lightbox body for an image the caller has already authorized: the link is minted here, bound to the
     * viewing user, and lives only as long as the open modal.
     */
    public static function lightboxView(Document $document, User $viewer): View
    {
        return view('filament.documents.lightbox', [
            'src' => DocumentUrl::preview($document, $viewer),
            'name' => $document->name,
        ]);
    }

    /**
     * Renames the file and edits its description. The name is cleaned by the Action, so what the Admin typed
     * is not necessarily what is stored.
     */
    public static function rename(): Action
    {
        return Action::make('rename')
            ->label(__('documents.actions.rename'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(__('documents.actions.rename'))
            ->modalSubmitActionLabel(__('documents.actions.rename_submit'))
            ->fillForm(static fn (Document $record): array => ['name' => $record->name, 'description' => $record->description])
            ->schema([
                TextInput::make('name')
                    ->label(__('documents.fields.name'))
                    ->required()
                    ->maxLength(DocumentFileName::MAX_LENGTH),
                Textarea::make('description')
                    ->label(__('documents.fields.description'))
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->action(static function (array $data, Document $record): void {
                try {
                    app(RenameDocument::class)->handle(
                        self::user(),
                        $record,
                        is_string($data['name'] ?? null) ? $data['name'] : '',
                        is_string($data['description'] ?? null) ? $data['description'] : null,
                    );
                } catch (ValidationException $e) {
                    throw self::underModal($e);
                }

                Notification::make()->success()->title(__('documents.notifications.renamed'))->send();
            });
    }

    /**
     * Shows or hides the file for the client. Offered only for a file that can ever be shared (a project, a task
     * or a comment that is not internal), never for a client-owned or unattached file.
     */
    public static function toggleVisibility(): Action
    {
        return Action::make('toggleVisibility')
            ->label(static fn (Document $record): string => $record->visible_to_client ? __('documents.actions.hide_from_client') : __('documents.actions.show_to_client'))
            ->icon(static fn (Document $record): Heroicon => $record->visible_to_client ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedUsers)
            ->visible(static fn (Document $record): bool => app(SetDocumentVisibility::class)->isShareable($record))
            ->action(static function (Document $record): void {
                $show = ! $record->visible_to_client;

                try {
                    app(SetDocumentVisibility::class)->handle(self::user(), $record, $show);
                } catch (ValidationException) {
                    // The only refusal is a file that cannot be shared; the switch is not offered for one.
                    Notification::make()->danger()->title(__('documents.errors.not_shareable'))->send();

                    return;
                }

                Notification::make()->success()->title($show ? __('documents.notifications.shown') : __('documents.notifications.hidden'))->send();
            });
    }

    /**
     * Deletes one file after a confirmation that names it. For the PDF of an issued document the confirmation is
     * extended (DO-03, D-21, ruling R-36-1): another heading and body, an explicit tick, and a "Smazat PDF" submit that
     * stays disabled until the tick is set. The refusal without the tick is also in the domain Action, not only here.
     */
    public static function delete(): Action
    {
        return Action::make('delete')
            ->label(__('documents.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(static fn (Document $record): string => DeleteDocument::isIssuedPdf($record)
                ? __('documents.actions.delete_issued_pdf.heading')
                : __('documents.actions.delete_heading'))
            ->modalDescription(static fn (Document $record): string => DeleteDocument::isIssuedPdf($record)
                ? __('documents.actions.delete_issued_pdf.description', ['name' => $record->name, 'number' => self::invoiceNumber($record)])
                : __('documents.actions.delete_description', ['name' => $record->name]))
            ->modalSubmitActionLabel(static fn (Document $record): string => DeleteDocument::isIssuedPdf($record)
                ? __('documents.actions.delete_issued_pdf.submit')
                : __('documents.actions.delete'))
            ->modalCancelActionLabel(static fn (Document $record): ?string => DeleteDocument::isIssuedPdf($record)
                ? (string) __('documents.actions.delete_issued_pdf.cancel')
                : null)
            ->schema(static fn (Document $record): array => DeleteDocument::isIssuedPdf($record)
                ? [
                    Checkbox::make(self::CONFIRM_ISSUED_PDF)
                        ->label(__('documents.actions.delete_issued_pdf.confirm'))
                        ->live()
                        ->accepted()
                        ->validationMessages(['accepted' => __('documents.actions.delete_issued_pdf.unconfirmed')]),
                ]
                : [])
            ->modalSubmitAction(static fn (Action $action, Document $record, Component $livewire): Action => DeleteDocument::isIssuedPdf($record)
                ? $action->disabled(! self::issuedPdfTicked($livewire))
                : $action)
            ->action(static function (array $data, Document $record): void {
                $confirmed = ($data[self::CONFIRM_ISSUED_PDF] ?? false) === true;
                $issuedPdf = DeleteDocument::isIssuedPdf($record);

                if (self::deleteOne($record, $issuedPdf && $confirmed)) {
                    Notification::make()
                        ->success()
                        ->title($issuedPdf ? __('documents.actions.delete_issued_pdf.deleted') : __('documents.notifications.deleted'))
                        ->send();

                    return;
                }

                Notification::make()->danger()->title(__('documents.notifications.delete_failed'))->send();
            });
    }

    /**
     * The bulk actions of the central listing (D-09, D-08): the ZIP of the selection and the deletion. Later actions
     * are appended here, so the resource does not change.
     *
     * @return list<BulkAction>
     */
    public static function bulkActions(): array
    {
        return [
            self::bulkArchive(),
            self::bulkDelete(),
        ];
    }

    /**
     * Asks for one ZIP of the selected files (D-08). The selection can be a whole filter result, so the files are
     * read in chunks and only their ids are kept; reading stops one past the configured limit, because
     * RequestDocumentArchive refuses such a selection anyway. The Action validates the ids and the limits and
     * queues the build; a refusal is shown as a danger notification and nothing is created.
     */
    public static function bulkArchive(): BulkAction
    {
        return BulkAction::make('bulkArchive')
            ->label(__('documents.archives.bulk_action'))
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('gray')
            ->chunkSelectedRecords(100)
            ->action(static function (iterable $records): void {
                $stopAt = max(1, (int) config('kokpit.documents.archive.max_files', 200)) + 1;
                $ids = [];

                foreach ($records as $record) {
                    if (! $record instanceof Document) {
                        continue;
                    }

                    $ids[] = $record->getKey();

                    if (count($ids) >= $stopAt) {
                        break;
                    }
                }

                try {
                    app(RequestDocumentArchive::class)->handle(self::user(), $ids);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(Arr::first(Arr::flatten($e->errors())))->send();

                    return;
                }

                Notification::make()
                    ->info()
                    ->title(__('documents.archives.queued.title'))
                    ->body(__('documents.archives.queued.body'))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Deletes the selected files after one confirmation. Every file goes through DeleteDocument on its own, so
     * one that cannot be deleted never stops the others; the Admin is told how many went and how many did not.
     * A selected file that no longer exists counts as one that did not go.
     *
     * The selection can be a whole filter result ("select all"), so the files are read in chunks of 100 by key
     * and never all at once; deleting a row of a chunk does not disturb the next one.
     */
    public static function bulkDelete(): BulkAction
    {
        return BulkAction::make('bulkDelete')
            ->label(__('documents.actions.bulk_delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('documents.actions.bulk_delete_heading'))
            ->modalDescription(static fn (HasTable $livewire): string => trans_choice('documents.actions.bulk_delete_description', self::selectedCount($livewire), ['count' => self::selectedCount($livewire)]))
            ->modalSubmitActionLabel(__('documents.actions.bulk_delete'))
            ->chunkSelectedRecords(100)
            ->action(static function (iterable $records, HasTable $livewire): void {
                $selected = self::selectedCount($livewire);
                $deleted = 0;
                $failed = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if ($record instanceof Document && DeleteDocument::isIssuedPdf($record)) {
                        // The PDF of an issued document needs its own explicit confirmation (D-21): never in bulk.
                        $skipped++;
                    } elseif ($record instanceof Document && self::deleteOne($record)) {
                        $deleted++;
                    } else {
                        $failed++;
                    }
                }

                // A selected file that was gone before it was read is a file that did not go.
                $failed += max(0, $selected - $deleted - $failed - $skipped);

                $notification = Notification::make()
                    ->title(__('documents.notifications.bulk_deleted', ['deleted' => $deleted, 'failed' => $failed]));

                if ($skipped > 0) {
                    $notification->body(__('documents.actions.delete_issued_pdf.bulk_skipped', ['skipped' => $skipped]));
                }

                match (true) {
                    $failed === 0 && $skipped === 0 => $notification->success(),
                    $deleted === 0 && $skipped === 0 => $notification->danger(),
                    default => $notification->warning(),
                };

                $notification->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * How many files the Admin selected, from the client's selection state and without loading the records
     * (the loaded records are cached by Filament and must stay the ones the action receives). With "select all"
     * the count is every selectable row minus the deselected ones.
     */
    private static function selectedCount(HasTable $livewire): int
    {
        $selected = data_get($livewire, 'selectedTableRecords');
        $deselected = data_get($livewire, 'deselectedTableRecords');

        if (data_get($livewire, 'isTrackingDeselectedTableRecords') === true) {
            return max(0, $livewire->getAllSelectableTableRecordsCount() - (is_array($deselected) ? count($deselected) : 0));
        }

        return is_array($selected) ? count($selected) : 0;
    }

    /**
     * Deletes one file for the signed-in Admin. `$confirmedIssuedPdf` is the Admin's explicit tick for the PDF of an
     * issued document (D-21). A refusal by the policy is not swallowed; any other failure
     * (the file was deleted in the meantime, the database failed) is reported without the file name and
     * answered with false, and nothing is deleted then. An object the disk refuses after the commit is no
     * failure here: the rows are gone and the stored object is removed after the commit or by the queued job.
     */
    private static function deleteOne(Document $document, bool $confirmedIssuedPdf = false): bool
    {
        try {
            app(DeleteDocument::class)->handle(self::user(), $document, $confirmedIssuedPdf);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report(new RuntimeException('A document could not be deleted ('.$e::class.').', previous: $e));

            return false;
        }

        return true;
    }

    /**
     * Stores the files of a submitted upload modal on the given owner (null for an unattached file) and tells
     * the Admin how many went. The owner, the client and the project come from the host, never from the form.
     *
     * @param  array<string, mixed>  $data
     */
    private static function attach(array $data, ?Model $owner): void
    {
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
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function uploadFields(?Model $owner, bool $unattached = false): array
    {
        $shareable = self::isShareable($owner);

        $maxKb = (int) config('kokpit.documents.max_size_kb');
        $maxFiles = (int) config('kokpit.documents.max_files_per_upload');

        return [
            FileUpload::make('files')
                ->label(__('documents.fields.files'))
                ->helperText(__('documents.hints.files', ['count' => $maxFiles, 'max' => (int) ($maxKb / 1024)]).($shareable ? '' : ' '.($unattached ? __('documents.hints.unattached') : __('documents.hints.client_hidden'))))
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

    /**
     * The number of the invoice that owns the PDF, for the body of the extended confirmation.
     */
    private static function invoiceNumber(Document $document): string
    {
        $number = Invoice::query()->whereKey($document->owner_id)->value('number');

        return is_string($number) ? $number : '';
    }

    /**
     * Whether the Admin ticked the confirmation in the mounted modal. Read from the modal state of the page, so the
     * submit button follows the tick as soon as the live field syncs.
     */
    private static function issuedPdfTicked(Component $livewire): bool
    {
        $mounted = data_get($livewire, 'mountedActions');

        if (! is_array($mounted) || $mounted === []) {
            return false;
        }

        return data_get($mounted[array_key_last($mounted)], 'data.'.self::CONFIRM_ISSUED_PDF) === true;
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
