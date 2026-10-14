<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Shared\Money\Money;

/**
 * One time entry as the report reads it: who worked, for whom, on what, for how long, and under which effective
 * billing (RE-01, RE-02).
 *
 * `billingType` and `rate` are the effective billing of the entry, resolved set-based by
 * {@see Queries\TimeReportReader} in the order task, parent task, project, client; `TimeEntryRateResolver` and
 * `TaskBillingResolver` stay the specification. `rate` is the hourly rate in the currency of the level it came
 * from, null when no level holds one. Nothing here is an amount: `EntryAmounts` prices an entry, once.
 */
final readonly class ReportEntry
{
    /**
     * @param  string  $workDate  the Prague day the entry started, `Y-m-d`
     * @param  int  $seconds  whole seconds; a running entry at its elapsed time
     * @param  bool  $billed  the entry is billed (its state), as opposed to unbilled
     * @param  string  $billingType  `hourly`, `fixed_price` or `non_billable`
     * @param  string|null  $projectBillingType  the billing type of the entry's own project (`hourly` or `fixed_price`), null without a project; it marks fixed-price project rows, whereas `billingType` is what prices the entry
     */
    public function __construct(
        public string $id,
        public string $workDate,
        public int $seconds,
        public bool $billable,
        public bool $billed,
        public string $userId,
        public string $userName,
        public string $clientId,
        public string $clientName,
        public string $clientCurrency,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $projectKey,
        public ?string $taskId,
        public ?string $taskReference,
        public ?string $taskTitle,
        public string $billingType,
        public ?Money $rate,
        public ?string $projectBillingType = null,
    ) {}
}
