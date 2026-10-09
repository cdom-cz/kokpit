<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use App\Domain\Tasks\Enums\TaskBillingType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Admin-only billing overrides of a task (D-12, D-13, TA-06): billing type,
 * hourly rate, fixed price, estimate and the internal note. Closed to Partners
 * by DeniesPartners and the admin-only policy, so a Partner reads zero rows and
 * `$task->billing` is null for a Partner.
 *
 * A task has a row only while it overrides something (D-14); a task without a
 * row inherits everything, nothing is copied from the project.
 *
 * Changes to the billing type, money and estimate are written to the activity
 * log through an allowlist (D-06); the internal note is free text and is never
 * logged. The activity log itself is Admin-only.
 *
 * `task_id` is not fillable: rows are created through `$task->billing()->create()`.
 *
 * The hourly rate and the fixed price are the virtual `hourly_rate` and
 * `fixed_price` Money attributes over their `*_minor` and `*_currency` column
 * pairs. The estimate is whole seconds.
 *
 * @property string $id
 * @property string $task_id
 * @property TaskBillingType $billing_type
 * @property Money|null $hourly_rate
 * @property int|null $hourly_rate_minor
 * @property string|null $hourly_rate_currency
 * @property Money|null $fixed_price
 * @property int|null $fixed_price_minor
 * @property string|null $fixed_price_currency
 * @property int|null $estimate_seconds
 * @property string|null $internal_note
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'billing_type',
    'hourly_rate',
    'fixed_price',
    'estimate_seconds',
    'internal_note',
])]
#[LoggedAttributes([
    'task_id',
    'billing_type',
    'hourly_rate_minor',
    'hourly_rate_currency',
    'fixed_price_minor',
    'fixed_price_currency',
    'estimate_seconds',
])]
final class TaskBilling extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    protected $table = 'task_billing';

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_type' => TaskBillingType::class,
            'hourly_rate' => MoneyCast::class,
            'fixed_price' => MoneyCast::class,
            'estimate_seconds' => 'integer',
        ];
    }
}
