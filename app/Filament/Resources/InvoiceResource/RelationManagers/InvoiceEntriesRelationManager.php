<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\ReleaseEntries;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceableEntries;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Billing\EntryBilling;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Resources\TaskResource;
use App\Filament\Support\CreateDraftAction;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use WeakMap;

/**
 * The tab of the time linked to a document (IN-02, IN-10; D-04 to D-06, UI-SPEC C4, ruling R-25-2). Admin only.
 *
 * On a draft it is "Záznamy času" with two actions: "Přidat nový čas" links newly tracked time through
 * {@see AddEntriesToDraft} (new lines are appended, an edited line is never rewritten) and the bulk action
 * "Odpojit od faktury" returns the selected entries to unbilled through {@see ReleaseEntries} (the lines stay, the
 * Admin edits or deletes them). On an issued document the same tab is "Navázaný čas": a read-only list, the time is
 * billed and locked and nothing here can unlink it.
 *
 * Nothing here writes a row. Every id from the browser is re-read through the scoped queries (the picker offers only
 * the eligible entries of the client of the draft, the selection of the bulk action is read back through the table
 * query of this very document) and the Actions filter them again under the row lock. The Sazba and Částka columns come
 * from the Admin-only readers of the billing of an entry: the snapshot of a billed entry, otherwise what it is worth
 * now.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The time linked to a document shows measured time, rates and amounts: a Partner never sees any of it.')]
final class InvoiceEntriesRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'entries';

    /**
     * The most entries the picker lists at once; a longer period is narrowed by the filters of the dialog.
     */
    private const int PICKER_LIMIT = 300;

    /** @var WeakMap<TimeEntry, array{rate: ?Money, amount: ?Money}>|null */
    private ?WeakMap $figures = null;

    /**
     * A draft is edited here; an issued document only shows its time.
     */
    public function isReadOnly(): bool
    {
        return ! $this->document()->isDraft();
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Invoice && ! $ownerRecord->isDraft()
            ? __('invoices.detail.tabs.time')
            : __('invoices.draft.entries.title');
    }

    /**
     * The button "Přidat nový čas" under the table of lines opens the same dialog.
     */
    #[On('draft-add-time')]
    public function openAddTime(): void
    {
        if ($this->isReadOnly()) {
            return;
        }

        $this->mountAction('addTime', [], ['table' => true]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['project.billing', 'task']))
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('invoices.draft.entries.columns.date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('project.name')
                    ->label(__('invoices.draft.entries.columns.project'))
                    ->placeholder(__('invoices.navigation.empty_value')),
                TextColumn::make('task.reference')
                    ->label(__('invoices.draft.entries.columns.task'))
                    ->placeholder(__('invoices.navigation.empty_value'))
                    ->url(static fn (TimeEntry $record): ?string => $record->task instanceof Task
                        ? TaskResource::getUrl('view', ['record' => $record->task])
                        : null),
                TextColumn::make('description')
                    ->label(__('invoices.draft.entries.columns.description'))
                    ->placeholder(__('invoices.navigation.empty_value'))
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('duration_seconds')
                    ->label(__('invoices.draft.entries.columns.duration'))
                    ->state(static fn (TimeEntry $record): string => DurationFormat::hoursMinutes((int) $record->duration_seconds))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label(__('invoices.draft.entries.footer'))
                            ->formatStateUsing(static fn (mixed $state): string => DurationFormat::hoursMinutes((int) $state)),
                    ),
                TextColumn::make('rate')
                    ->label(__('invoices.draft.entries.columns.rate'))
                    ->state(fn (TimeEntry $record): string => $this->figuresOf($record)['rate']?->format('cs') ?? __('invoices.navigation.empty_value'))
                    ->alignEnd(),
                TextColumn::make('amount')
                    ->label(__('invoices.draft.entries.columns.amount'))
                    ->state(fn (TimeEntry $record): string => $this->figuresOf($record)['amount']?->format('cs') ?? __('invoices.navigation.empty_value'))
                    ->alignEnd(),
            ])
            // The relation orders by start; a chosen sort replaces it, the id keeps equal starts in one order.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->reorder()
                ->orderBy($query->qualifyColumn('started_at'))
                ->orderBy($query->qualifyColumn('id')))
            ->defaultPaginationPageOption(25)
            ->summaries(pageCondition: false)
            ->headerActions([$this->addTimeAction()])
            ->toolbarActions([BulkActionGroup::make([$this->unlinkAction()])])
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->emptyStateHeading(__('invoices.draft.entries.empty_heading'))
            ->emptyStateDescription(__('invoices.draft.entries.empty_body'));
    }

    /**
     * "Přidat nový čas" (D-06): the period and the projects narrow the list, the Admin ticks the entries to link.
     */
    private function addTimeAction(): Action
    {
        return Action::make('addTime')
            ->label(__('invoices.draft.item_actions.add_time'))
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->modalHeading(__('invoices.draft.entries.add.heading'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel(__('invoices.draft.entries.add.submit'))
            ->modalCancelActionLabel(__('invoices.draft.entries.add.cancel'))
            ->schema([
                Select::make('period')
                    ->label(__('invoices.create.fields.period'))
                    ->options([
                        'this_month' => __('invoices.create.fields.period_this_month'),
                        'last_month' => __('invoices.create.fields.period_last_month'),
                        'custom' => __('invoices.create.fields.period_custom'),
                    ])
                    ->default('this_month')
                    ->native(false)
                    ->live()
                    ->required(),
                Grid::make(2)
                    ->visible(static fn (Get $get): bool => $get('period') === 'custom')
                    ->schema([
                        DatePicker::make('period_from')
                            ->label(__('invoices.create.fields.period_from'))
                            ->live()
                            ->required(),
                        DatePicker::make('period_to')
                            ->label(__('invoices.create.fields.period_to'))
                            ->live()
                            ->required(),
                    ]),
                Select::make('project_ids')
                    ->label(__('invoices.create.fields.projects'))
                    ->multiple()
                    ->options(fn (): array => CreateDraftAction::projectOptions($this->document()->client_id))
                    ->default(fn (): array => array_keys(CreateDraftAction::projectOptions($this->document()->client_id)))
                    ->live(),
                Checkbox::make('without_project')
                    ->label(__('invoices.create.fields.without_project'))
                    ->default(true)
                    ->live(),
                CheckboxList::make('entry_ids')
                    ->label(__('invoices.draft.entries.title'))
                    ->hiddenLabel()
                    ->options(fn (Get $get): array => $this->candidateOptions($get))
                    ->bulkToggleable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                assert($actor instanceof User);

                $ids = is_array($data['entry_ids'] ?? null) ? array_values($data['entry_ids']) : [];

                try {
                    $result = app(AddEntriesToDraft::class)->handle($actor, $this->document(), $ids);
                } catch (ValidationException $exception) {
                    throw self::underModal($exception);
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('invoices.draft.entries.add.toast', ['count' => $result['entries'], 'items' => $result['items']]))
                    ->send();

                // The editor above joins the new lines to its rows.
                $this->dispatch('draft-lines-changed');
            });
    }

    /**
     * "Odpojit od faktury" (D-05): the entries return to unbilled and unreserved at once; the lines are not touched.
     */
    private function unlinkAction(): BulkAction
    {
        return BulkAction::make('unlink')
            ->label(__('invoices.draft.entries.unlink.trigger'))
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('warning')
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->requiresConfirmation()
            ->modalHeading(__('invoices.draft.entries.unlink.heading'))
            ->modalDescription(static fn (Collection $records): string => (string) __('invoices.draft.entries.unlink.body', ['count' => $records->count()]))
            ->modalSubmitActionLabel(__('invoices.draft.entries.unlink.submit'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(ReleaseEntries::class)->handle($actor, $this->document(), $records->modelKeys());
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('invoices.draft.entries.unlink.toast'))->send();
            });
    }

    /**
     * The eligible entries of the client of the draft for the period and the projects of the dialog, as
     * "date · project · task · H:MM · description". The list is read again on every render; the Action decides which
     * of the ticked ids may really be linked.
     *
     * @return array<string, string>
     */
    private function candidateOptions(Get $get): array
    {
        $period = CreateDraftAction::period($get('period') ?? 'this_month', $get('period_from'), $get('period_to'));

        if ($period === null) {
            return [];
        }

        $projectIds = array_values(array_filter(
            is_array($get('project_ids')) ? $get('project_ids') : [],
            static fn (mixed $id): bool => is_string($id) && Str::isUuid($id),
        ));

        $rows = array_slice(app(InvoiceableEntries::class)->candidates(
            $this->document()->client_id,
            $period['from'],
            $period['to'],
            $projectIds,
            (bool) $get('without_project'),
            TimerClock::now(),
        ), 0, self::PICKER_LIMIT);

        $entries = TimeEntry::query()->whereIn('id', array_column($rows, 'id'))->get(['id', 'started_at', 'description'])->keyBy('id');
        $empty = (string) __('invoices.navigation.empty_value');
        $options = [];

        foreach ($rows as $row) {
            $entry = $entries->get($row['id']);
            $description = $entry instanceof TimeEntry ? $entry->description : null;

            $parts = [
                $entry instanceof TimeEntry ? $entry->started_at->setTimezone(FilamentTimezone::get())->format('j. n. Y') : $empty,
                $row['project_name'] ?? $empty,
                $row['task_reference'] ?? null,
                DurationFormat::hoursMinutes($row['seconds']),
                filled($description) ? Str::limit((string) $description, 60) : null,
            ];

            $options[$row['id']] = implode(' · ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
        }

        return $options;
    }

    /**
     * The rate and the amount of an entry: the snapshot of a billed one, otherwise what the entry is worth now. A
     * fixed-price or rate-less entry has neither (a dash).
     *
     * @return array{rate: ?Money, amount: ?Money}
     */
    private function figuresOf(TimeEntry $entry): array
    {
        $this->figures ??= new WeakMap;

        if (isset($this->figures[$entry])) {
            return $this->figures[$entry];
        }

        if ($entry->billing_state === BillingState::Billed) {
            return $this->figures[$entry] = ['rate' => $entry->billedRate(), 'amount' => $entry->billedAmount()];
        }

        $snapshot = app(EntryBilling::class)->snapshot($entry);
        $currency = $snapshot['billed_rate_currency'];

        return $this->figures[$entry] = [
            'rate' => $snapshot['billed_rate_minor'] !== null && $currency !== null ? Money::ofMinor($snapshot['billed_rate_minor'], $currency) : null,
            'amount' => $snapshot['billed_amount_minor'] !== null && $currency !== null ? Money::ofMinor($snapshot['billed_amount_minor'], $currency) : null,
        ];
    }

    private function document(): Invoice
    {
        $owner = $this->getOwnerRecord();
        assert($owner instanceof Invoice);

        return $owner;
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path. A key the dialog does not
     * show lands on the list of entries, the closest field.
     */
    private static function underModal(ValidationException $exception): ValidationException
    {
        $mapped = [];

        foreach ($exception->errors() as $messages) {
            $path = 'mountedActions.0.data.entry_ids';
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
