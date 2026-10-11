<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\RendersTaskCommentThread;
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
            // No file attachments and no attach button: nothing an upload could land on (A10).
            // The Action cleans the value again, so the editor is only a convenience.
            RichEditor::make('body')
                ->label(__('kokpit.tasks.comments.fields.body'))
                ->required()
                ->fileAttachments(false)
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'link'],
                    ['blockquote', 'bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ]),
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
