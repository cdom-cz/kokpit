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
 * The task columns and entries (TA-05, D-09, D-13).
 *
 * The Admin builders serve the Admin resource only, so they may show the people,
 * the checklist, the tags and the dates freely. The Partner builders are the
 * only builders a Partner surface may use: their names are pinned by the
 * constants below, which a test compares with the built lists, so a column or
 * an entry cannot be added to a Partner surface without a visible change of the
 * pin. The title is searchable but not sortable: the Czech collation mechanism
 * for sorted text is not built yet (CONTRIBUTING, Ordering).
 */
final class TaskColumns
{
    /** @var list<string> */
    public const array PARTNER_COLUMN_NAMES = ['reference', 'title', 'project.key', 'status', 'priority', 'due_date', 'assignee.name'];

    /** @var list<string> */
    public const array PARTNER_ENTRY_NAMES = ['reference', 'title', 'project.key', 'status', 'priority', 'start_date', 'due_date', 'assignee.name', 'requester.name', 'parent.reference', 'description', 'escalated_at'];

    /**
     * List columns of the Partner task list (KB-03). No tags, checklist, billing,
     * estimate or history; only the reference and the title are searchable.
     *
     * @return list<TextColumn>
     */
    public static function partnerColumns(): array
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
                ->badge(),
            TextColumn::make('priority')
                ->label(__('kokpit.tasks.fields.priority'))
                ->badge(),
            TextColumn::make('due_date')
                ->label(__('kokpit.tasks.fields.due_date'))
                ->date()
                ->placeholder(__('kokpit.tasks.empty_value')),
            TextColumn::make('assignee.name')
                ->label(__('kokpit.tasks.fields.assignee'))
                ->placeholder(__('kokpit.tasks.empty_value')),
        ];
    }

    /**
     * Detail entries of the Partner task page. The description goes through
     * RichText::render like the Admin's; the escalation entry shows only while
     * the task is escalated.
     *
     * @return list<TextEntry>
     */
    public static function partnerEntries(): array
    {
        return [
            TextEntry::make('reference')->label(__('kokpit.tasks.fields.reference')),
            TextEntry::make('title')->label(__('kokpit.tasks.fields.title')),
            TextEntry::make('project.key')->label(__('kokpit.tasks.fields.project')),
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
            TextEntry::make('parent.reference')
                ->label(__('kokpit.tasks.fields.parent'))
                ->placeholder(__('kokpit.tasks.empty_value'))
                ->visible(static fn (Task $record): bool => $record->parent_id !== null),
            TextEntry::make('description')
                ->label(__('kokpit.tasks.fields.description'))
                ->formatStateUsing(static fn (?string $state): HtmlString => RichText::render($state))
                ->placeholder(__('kokpit.tasks.empty_value'))
                ->columnSpanFull(),
            TextEntry::make('escalated_at')
                ->label(__('kokpit.partner_tasks.escalation.label'))
                ->badge()
                ->color('danger')
                ->state(static fn (Task $record): ?string => $record->escalated_at === null
                    ? null
                    : (string) __('kokpit.partner_tasks.escalation.value', [
                        'name' => (string) $record->escalatedBy?->name,
                        'datetime' => $record->escalated_at->format('j. n. Y H:i'),
                    ]))
                ->visible(static fn (Task $record): bool => $record->escalated_at !== null),
        ];
    }

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
