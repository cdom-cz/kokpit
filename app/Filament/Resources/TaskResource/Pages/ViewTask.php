<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\EstimateHours;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\ClearEscalation;
use App\Domain\Tasks\Actions\ToggleTaskChecklistItem;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Billing\BillingSource;
use App\Domain\Tasks\Billing\EffectiveBilling;
use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\CreateTimeEntry;
use App\Domain\TimeTracking\Billing\BillableDefault;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\TimeTotals;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TimeEntryResource;
use App\Filament\Support\TaskColumns;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * The full task page at /admin/tasks/KEY-N (D-09).
 */
final class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    /**
     * The time sums of the task in this request; not a public property, so it is never in the snapshot.
     *
     * @var array{worked: int, billed: int, unbilled: int, non_billable: int}|null
     */
    private ?array $timeTotals = null;

    public function getTitle(): string
    {
        $task = $this->getRecord();

        return $task instanceof Task ? $task->reference.' · '.$task->title : __('kokpit.tasks.model_label');
    }

    /**
     * The resource infolist plus the Admin-only effective billing (D-14): what will
     * really apply to the task and which level supplied each value.
     */
    public function infolist(Schema $schema): Schema
    {
        $schema = parent::infolist($schema);

        $components = [];

        foreach ($schema->getComponents(withHidden: true) as $component) {
            // The status can be changed right where it is read.
            if ($component instanceof TextEntry && $component->getName() === 'status') {
                $component->hintAction($this->changeStatusAction());
            }

            $components[] = $component;

            // The escalation reads next to the priority (UI-SPEC U-12).
            if ($component instanceof TextEntry && $component->getName() === 'priority') {
                $components[] = $this->escalationEntry();
            }
        }

        return $schema->components([
            ...$components,
            $this->checklistSection(),
            $this->effectiveBillingSection(),
            $this->timeSection(),
        ]);
    }

    /**
     * The private checklist of the task (TA-03): one checkbox per item, ticked in place. Checked for
     * the Admin before anything is read, like the billing and time sections, and shown only when the
     * task has at least one item (the counts are the ones the progress entry already caches). Adding,
     * editing and removing items stays on the edit page.
     */
    private function checklistSection(): Section
    {
        return Section::make(__('kokpit.tasks.checklist.heading'))
            ->columnSpanFull()
            ->visible(static fn (Task $record): bool => app(PartnerContext::class)->isAdmin()
                && TaskColumns::checklistProgress($record) !== null)
            ->schema([
                View::make('filament.tasks.checklist')
                    ->viewData(function (): array {
                        $task = $this->getRecord();
                        assert($task instanceof Task);

                        return [
                            'items' => $task->checklistItems()->get(),
                            'readOnly' => $task->trashed(),
                        ];
                    }),
            ]);
    }

    /**
     * Ticks or unticks one checklist item. /livewire/update is public and the page authorizes only
     * on mount, so the method checks the access itself, and the item id is client-controlled: the
     * Action refuses a malformed, unknown or foreign id (404) and an archived task (a notification).
     * The target state is passed, not a flip, so a repeated call changes nothing.
     */
    public function toggleChecklistItem(string $itemId, bool $done): void
    {
        abort_unless(TaskResource::canAccess(), 403);

        $task = $this->getRecord();
        assert($task instanceof Task);

        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            app(ToggleTaskChecklistItem::class)->handle($actor, $task, $itemId, $done);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

            return;
        }

        // The progress entry of the same response shows the new done/total.
        $task->loadCount(TaskColumns::checklistCounts());
    }

    /**
     * The tracked time of this task (UI-SPEC Surface C): worked and still unbilled hours and the
     * link to the entries of the task. No money. Checked for the Admin before anything is read, like
     * the billing section, so it never renders, or even queries, for anybody else. The time of the
     * subtasks is not added: each subtask shows its own.
     */
    private function timeSection(): Section
    {
        return Section::make(__('kokpit.time.task.section'))
            ->columns(3)
            ->visible(static fn (): bool => app(PartnerContext::class)->isAdmin())
            ->schema([
                TextEntry::make('time_worked')
                    ->label(__('kokpit.time.task.worked'))
                    ->state(fn (Task $record): string => DurationFormat::hoursMinutes($this->timeTotals($record)['worked'])),
                TextEntry::make('time_unbilled')
                    ->label(__('kokpit.time.task.unbilled'))
                    ->state(fn (Task $record): string => DurationFormat::hoursMinutes($this->timeTotals($record)['unbilled'])),
                TextEntry::make('time_entries_link')
                    ->hiddenLabel()
                    ->state(__('kokpit.time.task.show_entries'))
                    ->color('primary')
                    ->url(static fn (Task $record): string => TimeEntryResource::getUrl('index', [
                        'filters' => ['task_id' => ['value' => $record->getKey()]],
                    ])),
            ]);
    }

    /**
     * The sums of the task, read once per request for both figures.
     *
     * @return array{worked: int, billed: int, unbilled: int, non_billable: int}
     */
    private function timeTotals(Task $task): array
    {
        return $this->timeTotals ??= app(TimeTotals::class)->forTask($task, TimerClock::now());
    }

    /**
     * Who escalated the task and when; shown only while the task is escalated (D-06).
     */
    private function escalationEntry(): TextEntry
    {
        return TextEntry::make('escalated_at')
            ->label(__('kokpit.tasks.escalation.label'))
            ->badge()
            ->color('danger')
            ->state(static fn (Task $record): ?string => $record->escalated_at === null
                ? null
                : (string) __('kokpit.tasks.escalation.value', [
                    'name' => (string) $record->escalatedBy?->name,
                    'datetime' => $record->escalated_at->format('j. n. Y H:i'),
                ]))
            ->visible(static fn (Task $record): bool => $record->escalated_at !== null);
    }

    /**
     * The section is checked for the Admin before anything is resolved, so it can
     * never render, or even compute, for anybody else (D-13).
     */
    private function effectiveBillingSection(): Section
    {
        $isAdmin = static fn (): bool => app(PartnerContext::class)->isAdmin();

        return Section::make(__('kokpit.tasks.billing.effective.heading'))
            ->description(__('kokpit.tasks.billing.effective.description'))
            ->columns(2)
            ->visible($isAdmin)
            ->schema([
                $this->effectiveEntry('effective_type', 'type', static fn (EffectiveBilling $billing): string => TaskBillingType::from($billing->type)->getLabel(), static fn (EffectiveBilling $billing): BillingSource => $billing->typeSource),
                $this->effectiveEntry('effective_hourly_rate', 'hourly_rate', static fn (EffectiveBilling $billing): ?string => $billing->hourlyRate instanceof Money ? $billing->hourlyRate->format('cs').' '.__('kokpit.tasks.billing.effective.per_hour') : null, static fn (EffectiveBilling $billing): ?BillingSource => $billing->hourlyRateSource),
                $this->effectiveEntry('effective_fixed_price', 'fixed_price', static fn (EffectiveBilling $billing): ?string => $billing->fixedPrice instanceof Money ? $billing->fixedPrice->format('cs') : null, static fn (EffectiveBilling $billing): ?BillingSource => $billing->fixedPriceSource),
                $this->effectiveEntry('effective_estimate', 'estimate', static fn (EffectiveBilling $billing): ?string => $billing->estimateSeconds === null ? null : EstimateHours::fromSeconds($billing->estimateSeconds).' '.__('kokpit.tasks.billing.effective.hours'), static fn (EffectiveBilling $billing): ?BillingSource => $billing->estimateSource),
            ]);
    }

    /**
     * One resolved value with its source label, or the "not set" text.
     *
     * @param  Closure(EffectiveBilling): ?string  $value
     * @param  Closure(EffectiveBilling): ?BillingSource  $source
     */
    private function effectiveEntry(string $name, string $label, Closure $value, Closure $source): TextEntry
    {
        return TextEntry::make($name)
            ->label(__('kokpit.tasks.billing.effective.'.$label))
            ->state(static function (Task $record) use ($value, $source): string {
                $billing = app(TaskBillingResolver::class)->resolve($record);
                $text = $value($billing);
                $origin = $source($billing);

                if ($text === null || $origin === null) {
                    return __('kokpit.tasks.billing.effective.none');
                }

                return $text.' ('.__('kokpit.tasks.billing.effective.source', ['source' => $origin->getLabel()]).')';
            });
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // One click to start or stop tracking this task; hidden while it is archived (TI-01).
            TaskResource::timerAction()->record($this->getRecord()),
            // Time worked on this task is recorded from here with the task's context locked (D-06).
            $this->addTimeAction(),
            // An archived task is read-only until it is restored.
            EditAction::make()
                ->hidden(fn (): bool => $this->getRecord() instanceof Task && $this->getRecord()->trashed()),
            TaskResource::archiveAction()->record($this->getRecord()),
            TaskResource::restoreAction()->record($this->getRecord()),
            $this->clearEscalationAction(),
        ];
    }

    /**
     * "Přidat čas" (D-06): the standard time-entry fields in a modal with the client, project and task
     * taken from this task and locked, the start an hour ago and the end now. The write is the existing
     * {@see CreateTimeEntry} Action with every rule it owns; nothing is added here. `model()` makes
     * Filament hand the `?TimeEntry $record` closures of the shared fields a null record instead of the
     * page's task. The action is not shown for an archived task or for anybody who may not create entries.
     */
    private function addTimeAction(): Action
    {
        return Action::make('addTime')
            ->label(__('kokpit.time.task.add_time'))
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->model(TimeEntry::class)
            ->modalHeading(__('kokpit.time.task.add_time_heading'))
            ->modalSubmitActionLabel(__('kokpit.time.save'))
            ->successNotificationTitle(__('kokpit.time.saved'))
            ->visible(fn (): bool => $this->getRecord() instanceof Task
                && ! $this->getRecord()->trashed()
                && TimeEntryResource::canCreate())
            ->schema(TimeEntryResource::entryFields(lockContext: true))
            ->fillForm(function (): array {
                $task = $this->getRecord();
                assert($task instanceof Task);

                $now = TimerClock::now();

                return [
                    'client_id' => $this->taskClientId($task),
                    'project_id' => $task->project_id,
                    'task_id' => $task->getKey(),
                    'billable' => app(BillableDefault::class)->for($task),
                    'started_at' => $now->subHour()->format('Y-m-d H:i:s'),
                    'ended_at' => $now->format('Y-m-d H:i:s'),
                ];
            })
            ->action(function (array $data, Action $action): void {
                abort_unless(TaskResource::canAccess() && TimeEntryResource::canCreate(), 403);

                $task = $this->getRecord();
                assert($task instanceof Task);

                $actor = auth()->user();
                assert($actor instanceof User);

                // The context is read from the task record after the form data, so nothing the browser
                // sends (the disabled fields included) can change it.
                $payload = TimeEntryResource::actionData([
                    ...$data,
                    'client_id' => $this->taskClientId($task),
                    'project_id' => $task->project_id,
                    'task_id' => $task->getKey(),
                ]);

                try {
                    app(CreateTimeEntry::class)->handle($actor, $payload);
                } catch (ValidationException $e) {
                    // The locked fields cannot show an inline error, so a refusal of the context is a toast.
                    if (array_intersect(array_keys($e->errors()), ['client_id', 'project_id', 'task_id']) !== []) {
                        Notification::make()
                            ->danger()
                            ->title(__('kokpit.time.task.save_failed'))
                            ->body((string) collect($e->errors())->flatten()->first())
                            ->send();

                        $action->halt();

                        return;
                    }

                    $mapped = [];

                    foreach ($e->errors() as $key => $messages) {
                        $mapped["mountedActions.0.data.{$key}"] = $messages;
                    }

                    throw ValidationException::withMessages($mapped);
                }

                // The worked and unbilled figures of this response and the other time surfaces are current.
                $this->timeTotals = null;
                $this->dispatch('time-entry-saved');

                $action->success();
            });
    }

    /**
     * The client of the task's project, archived or not.
     */
    private function taskClientId(Task $task): ?string
    {
        $clientId = Project::query()->withTrashed()->whereKey($task->project_id)->value('client_id');

        return is_string($clientId) ? $clientId : null;
    }

    /**
     * Clears the escalation flag through the domain Action (D-06); visible only
     * while the task is escalated and not archived. A confirmation, not a
     * destructive colour: no data is deleted.
     */
    private function clearEscalationAction(): Action
    {
        return Action::make('clearEscalation')
            ->label(__('kokpit.tasks.actions.clear_escalation'))
            ->icon(Heroicon::OutlinedFlag)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('kokpit.tasks.escalation.clear_heading'))
            ->modalDescription(__('kokpit.tasks.escalation.clear_description'))
            ->modalSubmitActionLabel(__('kokpit.tasks.actions.clear_escalation'))
            ->successNotificationTitle(__('kokpit.tasks.escalation.cleared'))
            ->visible(fn (): bool => $this->getRecord() instanceof Task
                && $this->getRecord()->escalated_at !== null
                && ! $this->getRecord()->trashed())
            ->action(function (Action $action): void {
                abort_unless(TaskResource::canAccess(), 403);

                $task = $this->getRecord();
                assert($task instanceof Task);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(ClearEscalation::class)->handle($actor, $task);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                    return;
                }

                $action->success();
            });
    }

    /**
     * The Admin changes the status on the task page with the same effect as saving it on the edit page.
     * The write is {@see UpdateTask} with the status only (board lock, end of the new column,
     * completed_at, one history row, the Partner side told once). The link is shown only while
     * {@see TaskResource::canEdit()} holds, so never on an archived task and never for a Partner; the
     * body re-checks {@see TaskResource::canAccess()} because /livewire/update is public.
     */
    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('kokpit.tasks.actions.change_status'))
            ->link()
            ->modalHeading(__('kokpit.tasks.actions.change_status_heading'))
            ->modalSubmitActionLabel(__('kokpit.tasks.actions.change_status_submit'))
            ->successNotificationTitle(__('kokpit.tasks.notifications.status_changed'))
            ->visible(fn (): bool => $this->getRecord() instanceof Task && TaskResource::canEdit($this->getRecord()))
            ->fillForm(function (): array {
                $task = $this->getRecord();
                assert($task instanceof Task);

                return ['status' => TaskResource::enumValue($task->status)];
            })
            ->schema([
                Select::make('status')
                    ->label(__('kokpit.tasks.fields.status'))
                    ->options(ProjectStatus::class)
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless(TaskResource::canAccess(), 403);

                $task = $this->getRecord();
                assert($task instanceof Task);

                $actor = auth()->user();
                assert($actor instanceof User);

                $status = TaskResource::enumValue($data['status'] ?? null);

                if ($status === null) {
                    throw ValidationException::withMessages(['mountedActions.0.data.status' => __('kokpit.tasks.errors.status_invalid')]);
                }

                try {
                    app(UpdateTask::class)->handle($actor, $task, ['status' => $status]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages([
                        'mountedActions.0.data.status' => $e->errors()['status'] ?? [(string) collect($e->errors())->flatten()->first()],
                    ]);
                }

                // The badge and the record of the same response show the new status.
                $task->refresh();

                $action->success();
            });
    }
}
