<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\RendersTaskCommentThread;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Komentáře" tab of the task page (TA-04, D-08): the discussion on a task or
 * a subtask for the Admin, as a thread of comments one under another, oldest first,
 * with an inline form below it (RendersTaskCommentThread).
 *
 * A comment is written only through the domain Action AddTaskComment, which
 * cleans the body. The tab is append-only: there is no edit or delete. The Admin
 * may mark a comment internal; a Partner never reads such a comment (the model is
 * Partner-scoped). The bodies are rendered through RichText::render, so even a row
 * written around the Action cannot inject markup.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The Admin tab shows internal comments and the internal flag; the Partner has its own comments tab on its task page.')]
final class TaskCommentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;
    use RendersTaskCommentThread;

    protected static string $relationship = 'comments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.tasks.comments.relation_title');
    }

    protected function commentFields(): array
    {
        return [
            // The editor keeps `fileAttachments(false)` by design (D-22): a file dropped into the editor would bypass
            // `documents`, the policy and the sanitiser. Files go through the separate `files` field below, which
            // hands them to AttachDocuments with the saved comment as owner (D-02).
            // The Action cleans the body again, so the editor is only a convenience.
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
            // checked, copied and removed them. The files of an internal comment are stored hidden (D-20).
            FileUpload::make('files')
                ->label(__('documents.comments.files_label'))
                ->helperText(__('documents.comments.files_hint', [
                    'count' => (int) config('kokpit.documents.max_files_per_upload'),
                    'max' => (int) ((int) config('kokpit.documents.max_size_kb') / 1024),
                ]).' '.__('documents.comments.internal_files_hint'))
                ->multiple()
                ->storeFiles(false)
                ->maxSize((int) config('kokpit.documents.max_size_kb'))
                ->maxFiles((int) config('kokpit.documents.max_files_per_upload')),
            Toggle::make('is_internal')
                ->label(__('kokpit.tasks.comments.fields.is_internal'))
                ->helperText(__('kokpit.tasks.comments.hints.is_internal'))
                ->default(false),
        ];
    }

    protected function acceptsInternalFlag(): bool
    {
        return true;
    }

    protected function emptyHeading(): string
    {
        return (string) __('kokpit.tasks.comments.empty_heading');
    }

    protected function emptyDescription(): string
    {
        return (string) __('kokpit.tasks.comments.empty_description');
    }
}
