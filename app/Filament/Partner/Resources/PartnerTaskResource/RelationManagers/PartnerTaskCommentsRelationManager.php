<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\RendersTaskCommentThread;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Komentáře" tab of the Partner task page (TA-07, TA-04, D-08): a thread of
 * comments one under another, oldest first, with an inline form below it
 * (RendersTaskCommentThread).
 *
 * The relation goes through the Partner-scoped TaskComment model, so an internal
 * comment is not part of the query: there is no internal badge, no toggle and no
 * count that could show one. A comment is written only through AddTaskComment
 * with the Partner as actor, which stores it as not internal whatever the request
 * says; the form does not even offer the flag. A comment may carry files (a separate
 * upload field, always visible to the Admin and the Partner, D-05); the thread lists
 * only the files the Partner-scoped query returns. The tab is append-only: no edit or
 * delete of a comment. Bodies are rendered through RichText::render, so a row written around the
 * Action cannot inject markup.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner reads the non-internal comments of an own task and adds a comment; the model scope drops internal rows and the Action forces the flag off.')]
final class PartnerTaskCommentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use RendersTaskCommentThread;

    protected static string $relationship = 'comments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.partner_tasks.comments.relation_title');
    }

    protected function commentFields(): array
    {
        // The editor keeps `fileAttachments(false)` by design (D-22): a file dropped into the editor would bypass
        // `documents`, the policy and the sanitiser. Files go through the separate `files` field below, which
        // hands them to AttachDocuments with the saved comment as owner (D-05).
        // The Action cleans the body again, so the editor is only a convenience.
        // There is deliberately no internal switch: a Partner comment is public by construction, and so
        // are its files (a Partner's upload is always visible).
        return [
            RichEditor::make('body')
                ->label(__('kokpit.tasks.comments.fields.body'))
                ->required()
                ->fileAttachments(false)
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'link'],
                    ['blockquote', 'bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ]),
            // Not stored by the field: the temporary objects stay where Livewire put them until the Action has
            // checked, copied and removed them. The limits are the Admin's (D-06).
            FileUpload::make('files')
                ->label(__('documents.comments.files_label'))
                ->helperText(__('documents.hints.partner_files', [
                    'count' => (int) config('kokpit.documents.max_files_per_upload'),
                    'max' => (int) ((int) config('kokpit.documents.max_size_kb') / 1024),
                ]))
                ->multiple()
                ->storeFiles(false)
                ->maxSize((int) config('kokpit.documents.max_size_kb'))
                ->maxFiles((int) config('kokpit.documents.max_files_per_upload')),
        ];
    }

    protected function acceptsInternalFlag(): bool
    {
        return false;
    }

    protected function emptyHeading(): string
    {
        return (string) __('kokpit.partner_tasks.comments.empty_heading');
    }

    protected function emptyDescription(): string
    {
        return (string) __('kokpit.partner_tasks.comments.empty_description');
    }
}
