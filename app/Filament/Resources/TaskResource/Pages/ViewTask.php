<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Domain\Projects\EstimateHours;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
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
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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

        return $schema->components([
            ...$schema->getComponents(withHidden: true),
            $this->effectiveBillingSection(),
        ]);
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
            // An archived task is read-only until it is restored.
            EditAction::make()
                ->hidden(fn (): bool => $this->getRecord() instanceof Task && $this->getRecord()->trashed()),
            TaskResource::archiveAction()->record($this->getRecord()),
            TaskResource::restoreAction()->record($this->getRecord()),
        ];
    }
}
