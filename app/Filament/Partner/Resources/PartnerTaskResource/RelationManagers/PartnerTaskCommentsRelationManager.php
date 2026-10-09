<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers;

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
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "Komentáře" tab of the Partner task page (TA-07, TA-04, D-08).
 *
 * The relation goes through the Partner-scoped TaskComment model, so an internal
 * comment is not part of the query: there is no internal column, no toggle and no
 * count that could show one. A comment is written only through AddTaskComment
 * with the Partner as actor, which stores it as not internal whatever the request
 * says; the form does not even offer the flag. The tab is append-only: a create
 * action and no edit, delete or bulk action. Bodies are rendered through
 * RichText::render, so a row written around the Action cannot inject markup.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner reads the non-internal comments of an own task and adds a comment; the model scope drops internal rows and the Action forces the flag off.')]
final class PartnerTaskCommentsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'comments';

    /**
     * The tab sits on the read-only task page, but the Partner comments there.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.partner_tasks.comments.relation_title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            // No file attachments and no attach button: nothing an upload could land on (A10).
            // The Action cleans the value again, so the editor is only a convenience.
            // There is deliberately no internal switch: a Partner comment is public by construction.
            RichEditor::make('body')
                ->label(__('kokpit.tasks.comments.fields.body'))
                ->required()
                ->fileAttachments(false)
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'link'],
                    ['blockquote', 'bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ]),
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
                            // Only the body is read from the form state: a forged internal flag never reaches the Action.
                            return app(AddTaskComment::class)->handle($actor, $owner, (string) ($data['body'] ?? ''));
                        } catch (ValidationException $e) {
                            throw $this->underModal($e);
                        }
                    }),
            ])
            ->emptyStateHeading(__('kokpit.partner_tasks.comments.empty_heading'))
            ->emptyStateDescription(__('kokpit.partner_tasks.comments.empty_description'));
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
