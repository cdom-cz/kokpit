<?php

declare(strict_types=1);

namespace App\Livewire\TimeTracking;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * "Doplnit záznam": the modal that fills in the project, task and description of the running
 * entry later (D-02) while it keeps running.
 *
 * The modal uses the shared entry form (client, project, task, description, start and the
 * billable toggle) with the same cascade, consistency and overlap rules as the entry pages, and
 * saves through UpdateTimeEntry, which owns every rule. A running entry keeps `ended_at` null.
 * The component using this trait provides the running entry of the signed-in user.
 */
trait CompletesRunningEntry
{
    /**
     * The running entry of the signed-in user, or null when nothing runs.
     */
    abstract protected function runningEntry(): ?TimeEntry;

    public function completeRunningEntryAction(): Action
    {
        return Action::make('completeRunningEntry')
            ->label(__('kokpit.time.timer.complete'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading(__('kokpit.time.timer.complete_heading'))
            ->modalSubmitActionLabel(__('kokpit.time.save'))
            // The shared entry fields read the entry as their record (the current client, project
            // and task stay selectable even when archived).
            ->record(fn (): ?TimeEntry => $this->runningEntry())
            ->visible(fn (): bool => $this->runningEntry() instanceof TimeEntry)
            ->fillForm(static fn (?TimeEntry $record): array => $record instanceof TimeEntry
                ? Arr::only($record->attributesToArray(), ['client_id', 'project_id', 'task_id', 'description', 'started_at', 'billable'])
                : [])
            ->schema(TimeEntryResource::entryFields(true))
            ->action(function (array $data, Action $action): void {
                $entry = $this->runningEntry();

                if (! $entry instanceof TimeEntry) {
                    Notification::make()->info()->title(__('kokpit.time.timer.nothing_running'))->send();
                    $this->dispatch('timer-stopped');

                    return;
                }

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(UpdateTimeEntry::class)->handle($actor, $entry, TimeEntryResource::actionData($data));
                } catch (ValidationException $e) {
                    // The modal form lives under the state path of the mounted action, so a bare
                    // field key from the Action is re-keyed to land next to its field.
                    $prefix = 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data.';
                    $messages = [];

                    foreach ($e->errors() as $key => $errors) {
                        $messages[str_starts_with($key, 'mountedActions.') ? $key : $prefix.$key] = $errors;
                    }

                    throw ValidationException::withMessages($messages);
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('kokpit.time.saved'))->send();
                $this->dispatch('time-entry-saved');
            });
    }
}
