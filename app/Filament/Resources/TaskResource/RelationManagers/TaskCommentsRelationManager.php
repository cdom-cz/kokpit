<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Text\RichText;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "Komentáře" tab of the task page (TA-04, D-08): the discussion on a task or
 * a subtask, oldest first, for the Admin.
 *
 * A comment is written only through the domain Action AddTaskComment, which
 * cleans the body. The tab is append-only: it offers a create action and no
 * edit, delete or bulk action. The Admin may mark a comment internal; a Partner
 * never reads such a comment (the model is Partner-scoped). The bodies are
 * rendered through RichText::render, so even a row written around the Action
 * cannot inject markup.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The Admin tab shows internal comments and the internal flag; the Partner has its own comments tab on its task page.')]
final class TaskCommentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'comments';

    /**
     * The tab is on the read-only task page, but the Admin comments there.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.tasks.comments.relation_title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('author'))
            ->columns([
                TextColumn::make('author.name')
                    ->label(__('kokpit.tasks.comments.fields.author'))
                    ->weight('bold'),
                TextColumn::make('created_at')
                    ->label(__('kokpit.tasks.comments.fields.created_at'))
                    ->dateTime(),
                TextColumn::make('body')
                    ->label(__('kokpit.tasks.comments.fields.body'))
                    ->html()
                    ->formatStateUsing(static fn (?string $state): Htmlable => RichText::render($state))
                    ->wrap(),
                TextColumn::make('is_internal')
                    ->label(__('kokpit.tasks.comments.fields.visibility'))
                    ->badge()
                    ->color('warning')
                    ->state(static fn (TaskComment $record): ?string => $record->is_internal ? (string) __('kokpit.tasks.comments.badges.internal') : null),
                TextColumn::make('is_escalation')
                    ->label(__('kokpit.tasks.comments.fields.kind'))
                    ->badge()
                    ->color('danger')
                    ->state(static fn (TaskComment $record): ?string => $record->is_escalation ? (string) __('kokpit.tasks.comments.badges.escalation') : null),
            ])
            ->defaultSort('created_at')
            ->paginated(false)
            ->headerActions([
                CreateAction::make()
                    ->label(__('kokpit.tasks.comments.actions.create'))
                    ->modalHeading(__('kokpit.tasks.comments.actions.create_heading'))
                    ->modalSubmitActionLabel(__('kokpit.tasks.comments.actions.create_submit'))
                    ->createAnother(false)
                    ->using(function (array $data): Model {
                        $owner = $this->getOwnerRecord();
                        assert($owner instanceof Task);

                        $actor = auth()->user();
                        assert($actor instanceof User);

                        try {
                            return app(AddTaskComment::class)->handle(
                                $actor,
                                $owner,
                                (string) ($data['body'] ?? ''),
                                (bool) ($data['is_internal'] ?? false),
                            );
                        } catch (ValidationException $e) {
                            throw $this->underModal($e);
                        }
                    }),
            ])
            ->emptyStateHeading(__('kokpit.tasks.comments.empty_heading'))
            ->emptyStateDescription(__('kokpit.tasks.comments.empty_description'));
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path.
     */
    private function underModal(ValidationException $e): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            $path = "mountedActions.0.data.{$key}";
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
