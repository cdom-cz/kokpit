<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Actions\ClearEscalation;
use App\Domain\Tasks\Actions\EscalateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Partner\Resources\PartnerTaskResource;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

final class ViewPartnerTask extends ViewRecord
{
    protected static string $resource = PartnerTaskResource::class;

    public function getTitle(): string
    {
        $task = $this->getRecord();

        return $task instanceof Task ? $task->reference.' · '.$task->title : (string) __('kokpit.partner_tasks.model_label');
    }

    /**
     * The page is read-only: no edit or delete action, and no way to change the
     * status or the priority. The two actions are the escalation (D-06): "Eskalovat
     * úkol" with a required reason, hidden while the task is escalated, and "Zrušit
     * eskalaci", visible only while it is escalated and only to the Partner who is
     * the task's assignee (the policy decides, and the Action decides again on the
     * locked row).
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->escalateAction(),
            $this->clearEscalationAction(),
        ];
    }

    private function escalateAction(): Action
    {
        return Action::make('escalate')
            ->label(__('kokpit.tasks.actions.escalate'))
            ->icon(Heroicon::OutlinedFlag)
            ->color('warning')
            ->modalHeading(__('kokpit.tasks.escalation.escalate_heading'))
            ->modalDescription(__('kokpit.tasks.escalation.escalate_description'))
            ->modalSubmitActionLabel(__('kokpit.tasks.actions.escalate'))
            ->schema([
                // No file attachments and no attach button: nothing an upload could land on (A10).
                // The Action cleans the value again, so the editor is only a convenience.
                RichEditor::make('comment')
                    ->label(__('kokpit.tasks.escalation.reason'))
                    ->required()
                    ->fileAttachments(false)
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'strike', 'link'],
                        ['blockquote', 'bulletList', 'orderedList'],
                        ['undo', 'redo'],
                    ]),
            ])
            ->successNotificationTitle(__('kokpit.tasks.escalation.escalated'))
            ->visible(fn (): bool => $this->getRecord() instanceof Task && $this->getRecord()->escalated_at === null)
            ->action(function (array $data, Action $action): void {
                $task = $this->getRecord();
                assert($task instanceof Task);

                try {
                    app(EscalateTask::class)->handle($this->actor(), $task, (string) ($data['comment'] ?? ''));
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['mountedActions.0.data.comment' => $e->errors()['comment'] ?? []]);
                }

                $action->success();
            });
    }

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
            ->visible(function (): bool {
                $task = $this->getRecord();

                return $task instanceof Task
                    && $task->escalated_at !== null
                    && $this->actor()->can('clearEscalation', $task);
            })
            ->action(function (Action $action): void {
                $task = $this->getRecord();
                assert($task instanceof Task);

                try {
                    app(ClearEscalation::class)->handle($this->actor(), $task);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                    return;
                }

                $action->success();
            });
    }

    private function actor(): User
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        return $actor;
    }
}
