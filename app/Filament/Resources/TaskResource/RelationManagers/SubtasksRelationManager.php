<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\TaskResource;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "Podúkoly" tab of the task page (TA-01, D-11): the one-level subtasks of a
 * task. Admin only.
 *
 * A subtask is created only through the domain Action CreateTask with the owner
 * as parent, so it takes the next number of the same project counter and the
 * Action's parent rules apply. It then continues on its own edit page, like the
 * quick creation. A subtask cannot have subtasks: the tab is not offered on the
 * page of a subtask.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Subtasks carry the same internal fields as tasks; the Partner has its own task list.')]
final class SubtasksRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule {
        canViewForRecord as private canViewByAccessRule;
    }

    protected static string $relationship = 'subtasks';

    /**
     * The tab is on the read-only task page, but the Admin adds subtasks there.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.tasks.subtasks.relation_title');
    }

    /**
     * Only a root task offers subtasks: the page of a subtask has no such tab.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Task
            && $ownerRecord->parent_id === null
            && self::canViewByAccessRule($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label(__('kokpit.tasks.fields.title'))
                ->required()
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(static fn ($query) => $query->with('assignee'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('kokpit.tasks.fields.reference'))
                    ->weight('bold')
                    ->url(static fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record])),
                TextColumn::make('title')
                    ->label(__('kokpit.tasks.fields.title'))
                    ->wrap()
                    ->limit(80),
                TextColumn::make('status')
                    ->label(__('kokpit.tasks.fields.status'))
                    ->badge(),
                TextColumn::make('priority')
                    ->label(__('kokpit.tasks.fields.priority'))
                    ->badge(),
                TextColumn::make('assignee.name')
                    ->label(__('kokpit.tasks.fields.assignee'))
                    ->placeholder(__('kokpit.tasks.empty_value')),
                TextColumn::make('due_date')
                    ->label(__('kokpit.tasks.fields.due_date'))
                    ->date()
                    ->placeholder(__('kokpit.tasks.empty_value')),
            ])
            ->defaultSort('number')
            ->headerActions([
                CreateAction::make()
                    ->label(__('kokpit.tasks.subtasks.actions.create'))
                    ->modalHeading(__('kokpit.tasks.subtasks.actions.create_heading'))
                    ->modalSubmitActionLabel(__('kokpit.tasks.subtasks.actions.create_submit'))
                    ->createAnother(false)
                    ->using(function (array $data): Model {
                        $owner = $this->getOwnerRecord();
                        assert($owner instanceof Task);

                        $actor = auth()->user();
                        assert($actor instanceof User);

                        try {
                            // The project of the owner, an archived one included: CreateTask answers for it.
                            $project = Project::query()->withTrashed()->findOrFail($owner->project_id);

                            return app(CreateTask::class)->handle($actor, $project, ['title' => (string) ($data['title'] ?? '')], $owner);
                        } catch (ValidationException $e) {
                            throw $this->underModal($e);
                        }
                    })
                    ->successRedirectUrl(static fn (Model $record): string => TaskResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading(__('kokpit.tasks.subtasks.empty_heading'))
            ->emptyStateDescription(__('kokpit.tasks.subtasks.empty_description'));
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path.
     * The parent and project errors have no field of their own, so they show under the title.
     */
    private function underModal(ValidationException $e): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            $field = in_array($key, ['parent', 'project_id'], true) ? 'title' : $key;
            $path = "mountedActions.0.data.{$field}";
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
