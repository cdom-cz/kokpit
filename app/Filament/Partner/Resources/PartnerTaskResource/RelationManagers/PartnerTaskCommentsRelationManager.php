<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Concerns\RendersTaskCommentThread;
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
 * says; the form does not even offer the flag. The tab is append-only: no edit or
 * delete. Bodies are rendered through RichText::render, so a row written around the
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
        // No file attachments and no attach button: nothing an upload could land on (A10).
        // The Action cleans the value again, so the editor is only a convenience.
        // There is deliberately no internal switch: a Partner comment is public by construction.
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
