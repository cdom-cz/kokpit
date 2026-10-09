<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\CancelEntriesBilling;
use App\Domain\TimeTracking\Actions\DeleteTimeEntry;
use App\Domain\TimeTracking\Actions\MarkEntriesBilled;
use App\Domain\TimeTracking\Billing\BillableDefault;
use App\Domain\TimeTracking\Enums\BillingBadge;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\EntryContextOptions;
use App\Domain\TimeTracking\Queries\OverlapFinder;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\EditTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TimeEntryResource\Pages\ViewTimeEntry;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Throwable;

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
     * A billed entry is locked (D-06): the policy cannot say so, because the Admin passes
     * `KokpitPolicy::before()` for every ability, so the screens add the condition here. The
     * real guards stay the Actions and the database trigger.
     */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && ! ($record instanceof TimeEntry && $record->isBilled());
    }

    /**
     * Neither a billed nor a running entry is deleted from the screens: the stop of the timer is
     * the way to end a running one.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && ! ($record instanceof TimeEntry && ($record->isBilled() || $record->isRunning()));
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
                        ->options(static fn (?TimeEntry $record, Get $get): array => self::withCurrent(
                            app(EntryContextOptions::class)->clients(),
                            $record?->client_id,
                            $get('client_id'),
                            static fn (string $id): ?string => Client::query()->withTrashed()->whereKey($id)->value('name'),
                        ))
                        ->searchable()
                        ->required()
                        ->live()
                        ->validationMessages([
                            'required' => __('kokpit.time.errors.client_required'),
                            'in' => __('kokpit.time.errors.client_required'),
                        ])
                        ->afterStateUpdated(static function (Get $get, Set $set, mixed $state): void {
                            self::clientChanged($get, $set, is_string($state) && $state !== '' ? $state : null);
                        })
                        ->native(false),
                    Select::make('project_id')
                        ->label(__('kokpit.time.fields.project'))
                        ->options(static fn (?TimeEntry $record, Get $get): array => self::withCurrent(
                            app(EntryContextOptions::class)->projects(self::id($get('client_id'))),
                            $record?->project_id,
                            $get('project_id'),
                            static fn (string $id): ?string => ($project = Project::query()->withTrashed()->find($id)) instanceof Project
                                ? $project->key.' · '.$project->name
                                : null,
                        ))
                        ->searchable()
                        ->live()
                        ->validationMessages(['in' => __('kokpit.time.errors.inconsistent_context')])
                        ->afterStateUpdated(static function (Get $get, Set $set, mixed $state): void {
                            self::projectChanged($get, $set, is_string($state) && $state !== '' ? $state : null);
                        })
                        ->native(false),
                    Select::make('task_id')
                        ->label(__('kokpit.time.fields.task'))
                        ->options(static fn (?TimeEntry $record, Get $get): array => self::withCurrent(
                            app(EntryContextOptions::class)->tasks(self::id($get('client_id')), self::id($get('project_id'))),
                            $record?->task_id,
                            $get('task_id'),
                            static fn (string $id): ?string => ($task = Task::query()->withTrashed()->find($id)) instanceof Task
                                ? $task->reference.' · '.$task->title
                                : null,
                        ))
                        ->searchable()
                        ->live()
                        ->validationMessages(['in' => __('kokpit.time.errors.inconsistent_context')])
                        ->afterStateUpdated(static function (Get $get, Set $set, mixed $state): void {
                            self::taskChanged($get, $set, is_string($state) && $state !== '' ? $state : null);
                        })
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
                        ->required()
                        ->live(),
                    DateTimePicker::make('ended_at')
                        ->label(__('kokpit.time.fields.ended_at'))
                        ->seconds()
                        ->required()
                        ->live()
                        ->hidden($running),
                    // A running entry has no end: the badge stands in the place of Konec.
                    TextEntry::make('running_badge')
                        ->label(__('kokpit.time.fields.ended_at'))
                        ->state(__('kokpit.time.running_badge'))
                        ->badge()
                        ->color('warning')
                        ->visible($running),
                    TextEntry::make('duration_display')
                        ->label(__('kokpit.time.fields.duration'))
                        ->state(static fn (Get $get): string => self::liveDuration($get('started_at'), $get('ended_at'), $running)),
                    // The overlap never blocks a save (D-04); it only tells the Admin about it.
                    Callout::make(static fn (?TimeEntry $record, Get $get): string => self::overlapText($record, $get('started_at'), $get('ended_at'), $running) ?? '')
                        ->warning()
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->visible(static fn (?TimeEntry $record, Get $get): bool => self::overlapText($record, $get('started_at'), $get('ended_at'), $running) !== null)
                        ->columnSpanFull(),
                ]),
            Section::make(__('kokpit.time.sections.billing'))
                ->schema([
                    // Set by hand once, the toggle is the user's: a task change no longer presets it (D-03).
                    Hidden::make('billable_touched')
                        ->default(false)
                        ->afterStateHydrated(static function (Hidden $component, ?TimeEntry $record): void {
                            $component->state($record !== null && $record->billable !== app(BillableDefault::class)->for($record->task));
                        })
                        ->dehydrated(false),
                    Toggle::make('billable')
                        ->label(__('kokpit.time.fields.billable'))
                        ->default(true)
                        ->live()
                        ->afterStateUpdated(static function (Set $set): void {
                            $set('billable_touched', true);
                        })
                        ->helperText(static fn (Get $get): ?string => $get('billable_touched') !== true && $get('billable') === false && self::id($get('task_id')) !== null
                            ? (string) __('kokpit.time.billable_preset_helper')
                            : null),
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
            // A billed entry is locked on every screen (D-06): this says why and what to do.
            Callout::make(__('kokpit.time.locked_callout.heading'))
                ->description(__('kokpit.time.locked_callout.body'))
                ->warning()
                ->icon(Heroicon::OutlinedLockClosed)
                ->visible(static fn (?TimeEntry $record): bool => $record instanceof TimeEntry && $record->isBilled()),
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
                    TextEntry::make('billing_badge')
                        ->label(__('kokpit.time.fields.billing_state'))
                        ->state(static fn (TimeEntry $record): BillingBadge => $record->billingBadge())
                        ->badge(),
                    TextEntry::make('billed_at')
                        ->label(__('kokpit.time.fields.billed_at'))
                        ->dateTime(LocalisationServiceProvider::DATE_TIME_FORMAT)
                        ->visible(static fn (TimeEntry $record): bool => $record->isBilled()),
                ]),
        ]);
    }

    /**
     * The columns of an entry list, shared with the other lists of entries: Datum, Čas, Klient,
     * Projekt, Úkol, Popis, Trvání and the billing badge.
     *
     * @return list<TextColumn>
     */
    public static function tableColumns(): array
    {
        return [
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
            // The lock icon of a billed row explains why it has no edit or delete action.
            TextColumn::make('billing_badge')
                ->label(__('kokpit.time.fields.billing_state'))
                ->state(static fn (TimeEntry $record): BillingBadge => $record->billingBadge())
                ->badge(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::tableColumns())
            // Newest start first. The id is the tie-breaker, so rows with equal starts keep one
            // order across pages; it also stays the last key when a column sort is chosen.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderByDesc($query->qualifyColumn('started_at'))
                ->orderByDesc($query->qualifyColumn('id')))
            ->defaultPaginationPageOption(25)
            ->recordUrl(static fn (TimeEntry $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                EditAction::make()
                    ->label(__('kokpit.time.edit_entry'))
                    ->visible(static fn (TimeEntry $record): bool => self::canEdit($record)),
                self::deleteAction(DeleteAction::make()),
            ])
            ->toolbarActions([
                BulkActionGroup::make(self::billingBulkActions()),
            ])
            ->emptyStateHeading(__('kokpit.time.empty_heading'))
            ->emptyStateDescription(__('kokpit.time.empty_description'));
    }

    /**
     * "Smazat záznam" for an unbilled, finished entry, through the domain Action: danger, with
     * the confirmation that names the duration. A refusal (the entry was billed meanwhile) is a
     * danger toast and changes nothing.
     */
    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action
            ->label(__('kokpit.time.delete.action'))
            ->modalHeading(__('kokpit.time.delete.heading'))
            ->modalDescription(static fn (TimeEntry $record): string => __('kokpit.time.delete.body', [
                'duration' => DurationFormat::hoursMinutes((int) $record->elapsed_seconds),
            ]))
            ->modalSubmitActionLabel(__('kokpit.time.delete.action'))
            ->successNotificationTitle(__('kokpit.time.delete.done'))
            ->visible(static fn (TimeEntry $record): bool => self::canDelete($record))
            ->using(static function (TimeEntry $record, Component $livewire): bool {
                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(DeleteTimeEntry::class)->handle($actor, $record);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return false;
                }

                $livewire->dispatch('time-entry-deleted');

                return true;
            });
    }

    /**
     * The two bulk billing actions (TI-05, D-06), shared by every list of entries. The closures
     * hand only the selected keys to the Actions, which read the rows again under a lock and
     * decide the eligibility themselves; the confirmation text comes from `preview()`, the same
     * eligibility query, so the modal and the write cannot disagree.
     *
     * @return list<BulkAction>
     */
    public static function billingBulkActions(): array
    {
        return [
            BulkAction::make('markBilled')
                ->label(__('kokpit.time.billing.mark.action'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('primary')
                ->fetchSelectedRecords(false)
                ->requiresConfirmation()
                ->modalHeading(__('kokpit.time.billing.mark.heading'))
                ->modalDescription(static fn (HasTable $livewire): string => self::markDescription(self::selectedKeys($livewire)))
                ->modalSubmitActionLabel(__('kokpit.time.billing.mark.action'))
                ->action(static function (HasTable&Component $livewire): void {
                    $actor = auth()->user();
                    assert($actor instanceof User);

                    try {
                        $result = app(MarkEntriesBilled::class)->handle($actor, self::selectedKeys($livewire));
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('kokpit.time.billing.mark.done', ['count' => $result['billed']]))->send();
                    $livewire->dispatch('time-entry-saved');
                })
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('cancelBilling')
                ->label(__('kokpit.time.billing.cancel.action'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('warning')
                ->fetchSelectedRecords(false)
                ->requiresConfirmation()
                ->modalHeading(__('kokpit.time.billing.cancel.heading'))
                ->modalDescription(static fn (HasTable $livewire): string => self::cancelDescription(self::selectedKeys($livewire)))
                ->modalSubmitActionLabel(__('kokpit.time.billing.cancel.action'))
                ->action(static function (HasTable&Component $livewire): void {
                    $actor = auth()->user();
                    assert($actor instanceof User);

                    try {
                        $count = app(CancelEntriesBilling::class)->handle($actor, self::selectedKeys($livewire));
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('kokpit.time.billing.cancel.done', ['count' => $count]))->send();
                    $livewire->dispatch('time-entry-saved');
                })
                ->deselectRecordsAfterCompletion(),
        ];
    }

    /**
     * The confirmation text of "Označit jako vyfakturované" for the selected keys: how many
     * entries are locked and for how long, and, when some are skipped, how many and why.
     *
     * @param  list<string>  $keys
     */
    private static function markDescription(array $keys): string
    {
        $preview = app(MarkEntriesBilled::class)->preview($keys);

        $text = $preview['eligible'] === 0
            ? __('kokpit.time.errors.nothing_to_bill')
            : trans_choice('kokpit.time.billing.mark.body', $preview['eligible'], [
                'count' => $preview['eligible'],
                'duration' => DurationFormat::hoursMinutes($preview['eligible_seconds']),
            ]);

        if ($preview['skipped'] > 0) {
            $text .= ' '.trans_choice('kokpit.time.billing.mark.skipped', $preview['skipped'], ['skipped' => $preview['skipped']]);
        }

        return $text;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function cancelDescription(array $keys): string
    {
        $count = app(CancelEntriesBilling::class)->preview($keys)['eligible'];

        return $count === 0
            ? __('kokpit.time.errors.nothing_to_unbill')
            : trans_choice('kokpit.time.billing.cancel.body', $count, ['count' => $count]);
    }

    /**
     * The keys of the selected rows, without loading them: the browser's selection, which the
     * Actions treat as untrusted input.
     *
     * @return list<string>
     */
    private static function selectedKeys(HasTable $livewire): array
    {
        $keys = [];

        foreach ($livewire->getSelectedTableRecords(false) as $key) {
            $keys[] = (string) ($key instanceof Model ? $key->getKey() : $key);
        }

        return $keys;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimeEntries::route('/'),
            'create' => CreateTimeEntry::route('/create'),
            'view' => ViewTimeEntry::route('/{record}'),
            'edit' => EditTimeEntry::route('/{record}/edit'),
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

    /**
     * Choosing a client clears the project and the task that do not belong to it;
     * clearing the client clears both.
     */
    private static function clientChanged(Get $get, Set $set, ?string $clientId): void
    {
        $options = app(EntryContextOptions::class);
        $projectId = self::id($get('project_id'));
        $taskId = self::id($get('task_id'));

        if ($projectId !== null && ($clientId === null || $options->clientIdOfProject($projectId) !== $clientId)) {
            $set('project_id', null);
            $projectId = null;
        }

        if ($taskId === null) {
            return;
        }

        $taskProjectId = $options->projectIdOfTask($taskId);
        $taskClientId = $taskProjectId === null ? null : $options->clientIdOfProject($taskProjectId);

        if ($clientId === null || $taskClientId !== $clientId || ($projectId !== null && $taskProjectId !== $projectId)) {
            $set('task_id', null);
            self::presetBillable($get, $set, null);
        }
    }

    /**
     * Choosing a project sets its client and clears a task of another project;
     * clearing the project keeps the client and clears the task, which fixes the
     * project.
     */
    private static function projectChanged(Get $get, Set $set, ?string $projectId): void
    {
        $options = app(EntryContextOptions::class);
        $taskId = self::id($get('task_id'));

        if ($projectId !== null) {
            $clientId = $options->clientIdOfProject($projectId);

            if ($clientId !== null) {
                $set('client_id', $clientId);
            }
        }

        if ($taskId !== null && ($projectId === null || $options->projectIdOfTask($taskId) !== $projectId)) {
            $set('task_id', null);
            self::presetBillable($get, $set, null);
        }
    }

    /**
     * Choosing a task sets its project and its client, and presets the billable toggle
     * from the task (D-03); clearing the task presets it back.
     */
    private static function taskChanged(Get $get, Set $set, ?string $taskId): void
    {
        self::presetBillable($get, $set, $taskId);

        if ($taskId === null) {
            return;
        }

        $options = app(EntryContextOptions::class);
        $projectId = $options->projectIdOfTask($taskId);

        if ($projectId === null) {
            return;
        }

        $set('project_id', $projectId);

        $clientId = $options->clientIdOfProject($projectId);

        if ($clientId !== null) {
            $set('client_id', $clientId);
        }
    }

    /**
     * Presets the billable toggle from the task, unless the user already touched it. Only a
     * task that resolves as non-billable turns it off; a project, a fixed price or a
     * client never does (D-03).
     */
    private static function presetBillable(Get $get, Set $set, ?string $taskId): void
    {
        if ($get('billable_touched') === true) {
            return;
        }

        $task = $taskId === null ? null : Task::query()->withTrashed()->find($taskId);

        $set('billable', app(BillableDefault::class)->for($task));
    }

    /**
     * The duration `H:MM:SS` shown live beside the pickers: from the start to the end, or to
     * now for a running entry. Anything that does not form a positive interval shows a dash.
     */
    private static function liveDuration(mixed $start, mixed $end, bool $running): string
    {
        $from = self::pickerInstant($start);
        $to = $running ? TimerClock::now() : self::pickerInstant($end);

        if ($from === null || $to === null || $to->lessThanOrEqualTo($from)) {
            return __('kokpit.time.empty_value');
        }

        return DurationFormat::hoursMinutesSeconds((int) $from->diffInSeconds($to, true));
    }

    /**
     * The warning for the first other entry of the same user that overlaps the typed interval, or
     * null. A finished entry needs both ends; a running one is open-ended.
     */
    private static function overlapText(?TimeEntry $record, mixed $start, mixed $end, bool $running): ?string
    {
        $from = self::pickerInstant($start);
        $to = self::pickerInstant($end);

        if ($from === null || (! $running && $to === null)) {
            return null;
        }

        $userId = $record instanceof TimeEntry ? $record->user_id : auth()->id();

        if (! is_string($userId)) {
            return null;
        }

        $other = app(OverlapFinder::class)->first($userId, $from, $running ? null : $to, $record?->getKey());

        if (! $other instanceof TimeEntry) {
            return null;
        }

        $zone = FilamentTimezone::get();

        return __('kokpit.time.overlap_callout', [
            'label' => $other->task instanceof Task ? $other->task->reference.' · '.$other->task->title : (string) $other->client?->name,
            'from' => $other->started_at->setTimezone($zone)->format(LocalisationServiceProvider::TIME_FORMAT),
            'to' => $other->ended_at?->setTimezone($zone)->format(LocalisationServiceProvider::TIME_FORMAT) ?? '',
        ]);
    }

    /**
     * The instant of a date-time picker value as `$get()` returns it: `Y-m-d H:i:s` in the
     * application timezone (the picker shows the panel timezone, its state cast converts back).
     */
    private static function pickerInstant(mixed $state): ?CarbonImmutable
    {
        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        try {
            $instant = CarbonImmutable::createFromFormat('Y-m-d H:i:s', trim($state), (string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $instant;
    }

    /**
     * Adds the stored value of the entry being edited to the options when the form still
     * holds it: an entry whose client, project or task was archived later must keep
     * saving its other fields, and the archived row is not offered for a new choice.
     *
     * @param  array<string, string>  $options
     * @param  callable(string): ?string  $label
     * @return array<string, string>
     */
    private static function withCurrent(array $options, ?string $stored, mixed $current, callable $label): array
    {
        if ($stored === null || $current !== $stored || array_key_exists($stored, $options)) {
            return $options;
        }

        $text = $label($stored);

        return $text === null ? $options : [$stored => $text, ...$options];
    }

    private static function id(mixed $state): ?string
    {
        return is_string($state) && $state !== '' ? $state : null;
    }

    private static function text(mixed $state): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        return trim($state) === '' ? null : $state;
    }
}
