<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\EstimateHours;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\ClearEscalation;
use App\Domain\Tasks\Billing\BillingSource;
use App\Domain\Tasks\Billing\EffectiveBilling;
use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * The full task page at /admin/tasks/KEY-N (D-09).
 */
final class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

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
            $components[] = $component;

            // The escalation reads next to the priority (UI-SPEC U-12).
            if ($component instanceof TextEntry && $component->getName() === 'priority') {
                $components[] = $this->escalationEntry();
            }
        }

        return $schema->components([
            ...$components,
            $this->effectiveBillingSection(),
        ]);
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
            // An archived task is read-only until it is restored.
            EditAction::make()
                ->hidden(fn (): bool => $this->getRecord() instanceof Task && $this->getRecord()->trashed()),
            TaskResource::archiveAction()->record($this->getRecord()),
            TaskResource::restoreAction()->record($this->getRecord()),
            $this->clearEscalationAction(),
        ];
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
}
