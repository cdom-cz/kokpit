<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Admin-only billing terms of a project (D-05): billing type, hourly rate,
 * fixed price, estimate and the internal note. Closed to Partners by
 * DeniesPartners and the admin-only policy, so a Partner reads zero rows and
 * `$project->billing` is null for a Partner.
 *
 * Changes to the billing type, money and estimate are written to the activity
 * log through an allowlist (D-06); the internal note is free text and is never
 * logged. The activity log itself is Admin-only.
 *
 * `project_id` is not fillable: creation code sets it through
 * `$project->billing()`.
 *
 * The hourly rate and the fixed price are the virtual `hourly_rate` and
 * `fixed_price` Money attributes over their `*_minor` and `*_currency` column
 * pairs. The estimate is whole seconds.
 *
 * @property string $id
 * @property string $project_id
 * @property BillingType $billing_type
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
    'project_id',
    'billing_type',
    'hourly_rate_minor',
    'hourly_rate_currency',
    'fixed_price_minor',
    'fixed_price_currency',
    'estimate_seconds',
])]
final class ProjectBilling extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners, LogsAllowlistedActivity;

    protected $table = 'project_billing';

    /**
     * Whether any project of the client, archived ones included, holds a rate or
     * a fixed price. The client currency cannot change once this is true (the
     * lock lives with UpdateClient). A stored zero counts as held. Read by the
     * Admin or inside a system run, like the model itself.
     */
    public static function clientHoldsMoney(string $clientId): bool
    {
        return self::query()
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')
                ->from('projects')
                ->whereColumn('projects.id', 'project_billing.project_id')
                ->where('projects.client_id', $clientId))
            ->where(static fn ($query) => $query
                ->whereNotNull('hourly_rate_minor')
                ->orWhereNotNull('fixed_price_minor'))
            ->exists();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'hourly_rate' => MoneyCast::class,
            'fixed_price' => MoneyCast::class,
            'estimate_seconds' => 'integer',
        ];
    }
}
