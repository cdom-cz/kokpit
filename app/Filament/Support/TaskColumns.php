<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Tags\TagType;
use App\Domain\Shared\Text\RichText;
use App\Domain\Tasks\Models\Task;
use Closure;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The Admin task columns and entries (TA-05, D-09).
 *
 * The Partner builders follow with the Partner task list; until then these
 * builders serve the Admin resource only, so they may show the people and the
 * dates freely. The title is searchable but not sortable: the Czech collation
 * mechanism for sorted text is not built yet (CONTRIBUTING, Ordering).
 */
final class TaskColumns
{
    /**
     * List columns of the Admin task list.
     *
     * @return list<TextColumn>
     */
    public static function adminColumns(): array
    {
        return [
            TextColumn::make('reference')
                ->label(__('kokpit.tasks.fields.reference'))
                ->searchable()
                ->weight('bold'),
            TextColumn::make('title')
                ->label(__('kokpit.tasks.fields.title'))
                ->searchable()
                ->wrap()
                ->limit(80),
            TextColumn::make('project.key')
                ->label(__('kokpit.tasks.fields.project')),
            TextColumn::make('status')
                ->label(__('kokpit.tasks.fields.status'))
                ->badge()
                ->sortable(),
            TextColumn::make('priority')
                ->label(__('kokpit.tasks.fields.priority'))
                ->badge()
                ->sortable(),
            TextColumn::make('assignee.name')
                ->label(__('kokpit.tasks.fields.assignee'))
                ->placeholder(__('kokpit.tasks.empty_value')),
            // done/total of the private checklist, read from the counts the list query adds.
            TextColumn::make('checklist_progress')
                ->label(__('kokpit.tasks.checklist.progress'))
                ->state(static fn (Task $record): ?string => self::checklistProgress($record)),
            TextColumn::make('due_date')
                ->label(__('kokpit.tasks.fields.due_date'))
                ->date()
                ->placeholder(__('kokpit.tasks.empty_value'))
                ->sortable(),
            SpatieTagsColumn::make('tags')
                ->label(__('kokpit.tasks.fields.tags'))
                ->type(TagType::Task->value),
            TextColumn::make('updated_at')
                ->label(__('kokpit.tasks.fields.updated_at'))
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * Detail entries of the Admin task page.
     *
     * The description is stored as clean HTML and cleaned again on output through
     * RichText::render, so text written around the Action is harmless too (D-10).
     *
     * @return list<TextEntry>
     */
    public static function adminEntries(): array
    {
        return [
            TextEntry::make('reference')->label(__('kokpit.tasks.fields.reference')),
            TextEntry::make('title')->label(__('kokpit.tasks.fields.title')),
            TextEntry::make('project.name')
                ->label(__('kokpit.tasks.fields.project'))
                ->formatStateUsing(static fn (mixed $state, $record): string => $record->project === null
                    ? (string) $state
                    : $record->project->key.' · '.$record->project->name),
            TextEntry::make('status')->label(__('kokpit.tasks.fields.status'))->badge(),
            TextEntry::make('priority')->label(__('kokpit.tasks.fields.priority'))->badge(),
            TextEntry::make('start_date')
                ->label(__('kokpit.tasks.fields.start_date'))
                ->date()
                ->placeholder(__('kokpit.tasks.empty_value')),
            TextEntry::make('due_date')
                ->label(__('kokpit.tasks.fields.due_date'))
                ->date()
                ->placeholder(__('kokpit.tasks.empty_value')),
            TextEntry::make('assignee.name')
                ->label(__('kokpit.tasks.fields.assignee'))
                ->placeholder(__('kokpit.tasks.empty_value')),
            TextEntry::make('requester.name')
                ->label(__('kokpit.tasks.fields.requester'))
                ->placeholder(__('kokpit.tasks.empty_value')),
            TextEntry::make('description')
                ->label(__('kokpit.tasks.fields.description'))
                ->formatStateUsing(static fn (?string $state): HtmlString => RichText::render($state))
                ->placeholder(__('kokpit.tasks.empty_value'))
                ->columnSpanFull(),
            // Shown only when the task has a checklist.
            TextEntry::make('checklist_progress')
                ->label(__('kokpit.tasks.checklist.progress'))
                ->state(static fn (Task $record): ?string => self::checklistProgress($record))
                ->visible(static fn (Task $record): bool => self::checklistProgress($record) !== null),
            SpatieTagsEntry::make('tags')
                ->label(__('kokpit.tasks.fields.tags'))
                ->type(TagType::Task->value)
                ->placeholder(__('kokpit.tasks.empty_value')),
        ];
    }

    /**
     * The checklist counts (all items as `checklist_items_count`, done items as
     * `checklist_done_count`) for `withCount`: two subselects in the list query, so
     * a page of tasks needs no query per row.
     *
     * @return array<int|string, string|Closure(Builder<covariant Model>): Builder<covariant Model>>
     */
    public static function checklistCounts(): array
    {
        return [
            'checklistItems',
            'checklistItems as checklist_done_count' => static fn (Builder $items): Builder => $items->where('is_done', true),
        ];
    }

    /**
     * `done/total` of the checklist, or null when the task has no item. A row that
     * did not come from a query with the counts gets them with one count query.
     */
    public static function checklistProgress(Task $task): ?string
    {
        if (! array_key_exists('checklist_items_count', $task->getAttributes())) {
            $task->loadCount(self::checklistCounts());
        }

        $total = (int) $task->getAttribute('checklist_items_count');

        return $total === 0 ? null : (int) $task->getAttribute('checklist_done_count').'/'.$total;
    }
}
