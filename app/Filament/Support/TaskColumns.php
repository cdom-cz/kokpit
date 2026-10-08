<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Tags\TagType;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;

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
     * The description is shown as escaped text: it is stored as given until the
     * rich text sanitiser lands, and unsanitised HTML must not be rendered.
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
                ->placeholder(__('kokpit.tasks.empty_value'))
                ->columnSpanFull(),
            SpatieTagsEntry::make('tags')
                ->label(__('kokpit.tasks.fields.tags'))
                ->type(TagType::Task->value)
                ->placeholder(__('kokpit.tasks.empty_value')),
        ];
    }
}
