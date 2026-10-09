<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Billing;

use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\Tasks\Models\Task;

/**
 * The billable flag a new time entry starts with (D-03).
 *
 * True without a task and for every task except a resolved non-billable one,
 * including a subtask that inherits non-billable from its parent. A project has
 * no non-billable value and a fixed-price project stays billable. The value only
 * pre-sets the flag: the caller can always override it. The answer comes from
 * the single billing resolver, never from a second reading of the billing rows.
 *
 * Admin and system contexts only, like the resolver.
 */
final class BillableDefault
{
    public function __construct(private readonly TaskBillingResolver $resolver) {}

    public function for(?Task $task): bool
    {
        if ($task === null) {
            return true;
        }

        return $this->resolver->resolve($task)->isBillable();
    }
}
