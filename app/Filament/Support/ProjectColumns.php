<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Tags\TagType;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;

/**
 * The Partner-safe project columns and entries (D-07, PR-04).
 *
 * Both the Partner resource and the Admin project resource build the shared
 * part of their list and detail from these builders, so the two cannot drift.
 * The name lists are the single source a test pins: a surface that a Partner
 * can open may show exactly these fields, never the client, a rate, a price, an
 * estimate, the billing type or an internal note. The project tags are shown
 * with the tag type pinned to `project`, and the Tag Partner constraint limits
 * them to the tags of visible own projects (D-07).
 */
final class ProjectColumns
{
    /** @var list<string> */
    public const array PARTNER_COLUMN_NAMES = ['name', 'key', 'status', 'priority', 'start_date', 'end_date', 'tags'];

    /** @var list<string> */
    public const array PARTNER_ENTRY_NAMES = ['name', 'key', 'status', 'description', 'start_date', 'end_date', 'priority', 'tags'];

    /**
     * List columns for a Partner. Only name and key are searchable.
     *
     * @return list<TextColumn>
     */
    public static function partnerColumns(): array
    {
        return [
            TextColumn::make('name')
                ->label(__('kokpit.projects.fields.name'))
                ->searchable()
                ->sortable(),
            TextColumn::make('key')
                ->label(__('kokpit.projects.fields.key'))
                ->searchable()
                ->sortable(),
            TextColumn::make('status')
                ->label(__('kokpit.projects.fields.status'))
                ->badge(),
            TextColumn::make('priority')
                ->label(__('kokpit.projects.fields.priority'))
                ->badge(),
            TextColumn::make('start_date')
                ->label(__('kokpit.projects.fields.start_date'))
                ->date()
                ->placeholder(__('kokpit.projects.empty_value')),
            TextColumn::make('end_date')
                ->label(__('kokpit.projects.fields.end_date'))
                ->date()
                ->placeholder(__('kokpit.projects.empty_value')),
            SpatieTagsColumn::make('tags')
                ->label(__('kokpit.projects.fields.tags'))
                ->type(TagType::Project->value),
        ];
    }

    /**
     * Detail entries for a Partner.
     *
     * @return list<TextEntry>
     */
    public static function partnerEntries(): array
    {
        return [
            TextEntry::make('name')->label(__('kokpit.projects.fields.name')),
            TextEntry::make('key')->label(__('kokpit.projects.fields.key')),
            TextEntry::make('status')->label(__('kokpit.projects.fields.status'))->badge(),
            TextEntry::make('description')
                ->label(__('kokpit.projects.fields.description'))
                ->placeholder(__('kokpit.projects.empty_value'))
                ->columnSpanFull(),
            TextEntry::make('start_date')
                ->label(__('kokpit.projects.fields.start_date'))
                ->date()
                ->placeholder(__('kokpit.projects.empty_value')),
            TextEntry::make('end_date')
                ->label(__('kokpit.projects.fields.end_date'))
                ->date()
                ->placeholder(__('kokpit.projects.empty_value')),
            TextEntry::make('priority')->label(__('kokpit.projects.fields.priority'))->badge(),
            SpatieTagsEntry::make('tags')
                ->label(__('kokpit.projects.fields.tags'))
                ->type(TagType::Project->value)
                ->placeholder(__('kokpit.projects.empty_value')),
        ];
    }
}
