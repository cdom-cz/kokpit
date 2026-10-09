<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Billing;

use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;

/**
 * Resolves the effective billing of a task at read time, without copying
 * anything (D-14, TA-06). Phase 6 (billable flag, rate) and Phase 10 (invoice
 * items) call this instead of repeating the order.
 *
 * Levels, in order: the task's own billing row, the parent task's row (a
 * subtask only), the project's billing row, and for the hourly rate the client.
 * Per field the first level holding a value wins. A task row of type `inherit`
 * defers its type, a null amount or estimate defers that field, and a missing
 * row defers everything to the next level. The type and the fixed price never
 * reach the client, only the rate does. The estimate inherits literally like the
 * other fields; whether Phase 6 compares time against an inherited estimate is
 * left to Phase 6 (research A7).
 *
 * Admin and system contexts only (D-13): the rows it reads are closed to
 * Partners, and a Partner call is refused outright so the visible client row can
 * never leak a rate. Accepts a task with loaded relations; otherwise it loads
 * `billing`, `parent.billing`, `project.billing` and `project.client`, archived
 * parents, projects and clients included.
 */
final class TaskBillingResolver
{
    public function __construct(private readonly PartnerContext $context) {}

    /**
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when the project has no billing row, which CreateProject always writes
     */
    public function resolve(Task $task): EffectiveBilling
    {
        if (! $this->context->isAdmin() && ! $this->context->isSystem()) {
            throw new AuthorizationException('The effective billing is available to the Admin only.');
        }

        $task->loadMissing([
            'billing',
            'parent' => static fn ($query) => $query->withoutGlobalScopes([SoftDeletingScope::class]),
            'parent.billing',
            'project' => static fn ($query) => $query->withoutGlobalScopes([SoftDeletingScope::class]),
            'project.billing',
            'project.client' => static fn ($query) => $query->withoutGlobalScopes([SoftDeletingScope::class]),
        ]);

        $own = $task->billing;
        $parent = $task->parent_id === null ? null : $task->parent?->billing;
        $project = $task->project;
        $projectRow = $project?->billing;
        $client = $project?->client;

        if ($projectRow === null) {
            throw new LogicException('The project of the task has no billing row.');
        }

        [$type, $typeSource] = $this->type($own, $parent, $projectRow->billing_type->value);

        $hourlyRate = $this->first(
            [$own?->hourly_rate, BillingSource::Task],
            [$parent?->hourly_rate, BillingSource::ParentTask],
            [$projectRow->hourly_rate, BillingSource::Project],
            [$client?->hourly_rate, BillingSource::Client],
        );
        $fixedPrice = $this->first(
            [$own?->fixed_price, BillingSource::Task],
            [$parent?->fixed_price, BillingSource::ParentTask],
            [$projectRow->fixed_price, BillingSource::Project],
        );
        $estimate = $this->first(
            [$own?->estimate_seconds, BillingSource::Task],
            [$parent?->estimate_seconds, BillingSource::ParentTask],
            [$projectRow->estimate_seconds, BillingSource::Project],
        );

        return new EffectiveBilling(
            type: $type,
            typeSource: $typeSource,
            hourlyRate: $hourlyRate[0] instanceof Money ? $hourlyRate[0] : null,
            hourlyRateSource: $hourlyRate[1],
            fixedPrice: $fixedPrice[0] instanceof Money ? $fixedPrice[0] : null,
            fixedPriceSource: $fixedPrice[1],
            estimateSeconds: is_int($estimate[0]) ? $estimate[0] : null,
            estimateSource: $estimate[1],
        );
    }

    /**
     * The first level whose type is not `inherit`; the project type is the floor.
     *
     * @return array{string, BillingSource}
     */
    private function type(?TaskBilling $own, ?TaskBilling $parent, string $projectType): array
    {
        if ($own !== null && $own->billing_type !== TaskBillingType::Inherit) {
            return [$own->billing_type->value, BillingSource::Task];
        }

        if ($parent !== null && $parent->billing_type !== TaskBillingType::Inherit) {
            return [$parent->billing_type->value, BillingSource::ParentTask];
        }

        return [$projectType, BillingSource::Project];
    }

    /**
     * The first level that holds a value, with its source; both null when none does.
     *
     * @param  array{Money|int|null, BillingSource}  ...$levels
     * @return array{Money|int|null, BillingSource|null}
     */
    private function first(array ...$levels): array
    {
        foreach ($levels as [$value, $source]) {
            if ($value !== null) {
                return [$value, $source];
            }
        }

        return [null, null];
    }
}
