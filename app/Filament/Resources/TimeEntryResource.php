<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use App\Providers\LocalisationServiceProvider;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * The Admin time entry screens: the list, the create page and the entry page
 * (TI-02, TI-03).
 *
 * Nothing here writes a row: the pages hand the form state to the domain Actions
 * CreateTimeEntry and UpdateTimeEntry, which own every rule. The list shows the
 * entries of every user (there is one Admin); the timer, the side panel and the
 * timesheet are scoped to the signed-in user. Time entries are not globally
 * searchable: the panel opts resources in, and this one does not.
 *
 * `entryFields()` is a static builder so the running-entry modal reuses it.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Measured time is closed to Partners: the time entry screens are for the Admin only.')]
final class TimeEntryResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = TimeEntry::class;

    protected static ?string $slug = 'time-entries';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.time.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedClock;
    }

    public static function getModelLabel(): string
    {
        return __('kokpit.time.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kokpit.time.plural_model_label');
    }

    /**
     * Every row carries `elapsed_seconds`, a running entry at its elapsed time
     * bound to the PHP clock. The relations include archived rows, so an archived
     * client, project or task still names its entries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['client', 'project', 'task'])
            ->scopes(['withElapsedSeconds' => [TimerClock::now()]]);
    }

    /**
     * The three sections of the entry form. With `$running` true the entry has no
     * end yet, so Konec is left out and the "Běží" badge stands in its place.
     *
     * @return list<Section>
     */
    public static function entryFields(bool $running = false): array
    {
        return [
            Section::make(__('kokpit.time.sections.entry'))
                ->columns(2)
                ->schema([
                    Select::make('client_id')
                        ->label(__('kokpit.time.fields.client'))
                        ->options(static fn (): array => self::clientOptions())
                        ->searchable()
                        ->required()
                        ->validationMessages(['required' => __('kokpit.time.errors.client_required')])
                        ->native(false),
                    Textarea::make('description')
                        ->label(__('kokpit.time.fields.description'))
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ]),
            Section::make(__('kokpit.time.sections.time'))
                ->columns(2)
                ->schema([
                    DateTimePicker::make('started_at')
                        ->label(__('kokpit.time.fields.started_at'))
                        ->seconds()
                        ->required(),
                    DateTimePicker::make('ended_at')
                        ->label(__('kokpit.time.fields.ended_at'))
                        ->seconds()
                        ->required()
                        ->hidden($running),
                ]),
            Section::make(__('kokpit.time.sections.billing'))
                ->schema([
                    Toggle::make('billable')
                        ->label(__('kokpit.time.fields.billable'))
                        ->default(true),
                ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::entryFields());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('client.name')
                        ->label(__('kokpit.time.fields.client')),
                    TextEntry::make('project.name')
                        ->label(__('kokpit.time.fields.project'))
                        ->placeholder(__('kokpit.time.empty_value'))
                        ->url(static fn (TimeEntry $record): ?string => $record->project instanceof Project
                            ? ProjectResource::getUrl('view', ['record' => $record->project])
                            : null),
                    TextEntry::make('task.reference')
                        ->label(__('kokpit.time.fields.task'))
                        ->placeholder(__('kokpit.time.empty_value'))
                        ->url(static fn (TimeEntry $record): ?string => $record->task instanceof Task
                            ? TaskResource::getUrl('view', ['record' => $record->task])
                            : null),
                    TextEntry::make('started_at')
                        ->label(__('kokpit.time.fields.started_at'))
                        ->dateTime(LocalisationServiceProvider::DATE_TIME_SECONDS_FORMAT),
                    TextEntry::make('ended_at')
                        ->label(__('kokpit.time.fields.ended_at'))
                        ->dateTime(LocalisationServiceProvider::DATE_TIME_SECONDS_FORMAT)
                        ->placeholder(__('kokpit.time.running_badge')),
                    TextEntry::make('elapsed_seconds')
                        ->label(__('kokpit.time.fields.duration'))
                        ->state(static fn (TimeEntry $record): string => DurationFormat::hoursMinutesSeconds((int) $record->elapsed_seconds)),
                    TextEntry::make('description')
                        ->label(__('kokpit.time.fields.description'))
                        ->placeholder(__('kokpit.time.empty_value'))
                        ->columnSpanFull(),
                    TextEntry::make('billable')
                        ->label(__('kokpit.time.fields.billable'))
                        ->state(static fn (TimeEntry $record): string => $record->billable ? __('kokpit.time.yes') : __('kokpit.time.no')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('kokpit.time.fields.date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('time_range')
                    ->label(__('kokpit.time.fields.time_range'))
                    ->state(static fn (TimeEntry $record): HtmlString => self::timeRange($record))
                    ->html(),
                TextColumn::make('client.name')
                    ->label(__('kokpit.time.fields.client')),
                TextColumn::make('project.key')
                    ->label(__('kokpit.time.fields.project'))
                    ->placeholder(__('kokpit.time.empty_value')),
                TextColumn::make('task.reference')
                    ->label(__('kokpit.time.fields.task'))
                    ->placeholder(__('kokpit.time.empty_value'))
                    ->url(static fn (TimeEntry $record): ?string => $record->task instanceof Task
                        ? TaskResource::getUrl('view', ['record' => $record->task])
                        : null),
                TextColumn::make('description')
                    ->label(__('kokpit.time.fields.description'))
                    ->placeholder(__('kokpit.time.empty_value'))
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('elapsed_seconds')
                    ->label(__('kokpit.time.fields.duration'))
                    ->state(static fn (TimeEntry $record): string => DurationFormat::hoursMinutes((int) $record->elapsed_seconds))
                    ->sortable(),
            ])
            // Newest start first. The id is the tie-breaker, so rows with equal starts keep one
            // order across pages; it also stays the last key when a column sort is chosen.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderByDesc($query->qualifyColumn('started_at'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->defaultPaginationPageOption(25)
            ->recordUrl(static fn (TimeEntry $record): string => self::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('kokpit.time.empty_heading'))
            ->emptyStateDescription(__('kokpit.time.empty_description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimeEntries::route('/'),
            'create' => CreateTimeEntry::route('/create'),
            'view' => ViewTimeEntry::route('/{record}'),
        ];
    }

    /**
     * The form state in the shape of the domain Actions: empty text becomes null, the
     * pickers keep their values (the Action parses the instants and checks the context).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function actionData(array $data): array
    {
        return [
            'client_id' => self::text($data['client_id'] ?? null),
            'project_id' => self::text($data['project_id'] ?? null),
            'task_id' => self::text($data['task_id'] ?? null),
            'description' => self::text($data['description'] ?? null),
            'started_at' => self::text($data['started_at'] ?? null),
            'ended_at' => self::text($data['ended_at'] ?? null),
            'billable' => is_bool($data['billable'] ?? null) ? $data['billable'] : null,
        ];
    }

    /**
     * The time range of an entry in Prague time as plain text: `H:i–H:i`, or `H:i–`
     * while the entry runs.
     */
    public static function timeRangeText(TimeEntry $entry): string
    {
        $zone = FilamentTimezone::get();
        $from = $entry->started_at->setTimezone($zone)->format(LocalisationServiceProvider::TIME_FORMAT);

        return $entry->ended_at === null
            ? $from.'–'
            : $from.'–'.$entry->ended_at->setTimezone($zone)->format(LocalisationServiceProvider::TIME_FORMAT);
    }

    /**
     * The time range for the list: the text, followed by the warning badge "Běží" while
     * the entry runs.
     */
    public static function timeRange(TimeEntry $entry): HtmlString
    {
        $range = e(self::timeRangeText($entry));

        if ($entry->ended_at !== null) {
            return new HtmlString($range);
        }

        $badge = Blade::render(
            '<x-filament::badge color="warning" size="sm">{{ $label }}</x-filament::badge>',
            ['label' => __('kokpit.time.running_badge')],
        );

        return new HtmlString($range.' '.$badge);
    }

    private static function text(mixed $state): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        return trim($state) === '' ? null : $state;
    }

    /**
     * The clients an entry may be recorded for: the archive scope hides archived ones.
     *
     * @return array<string, string>
     */
    private static function clientOptions(): array
    {
        /** @var array<string, string> $clients */
        $clients = Client::query()->orderBy('name')->pluck('name', 'id')->all();

        return $clients;
    }
}
