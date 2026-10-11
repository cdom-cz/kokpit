<?php

declare(strict_types=1);

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * The Friday recap of a week: what went well and what to do differently. One per user and week.
 *
 * @property string $id
 * @property string $user_id
 * @property string $week_start
 * @property string $what_went_well
 * @property string $what_to_change
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['week_start', 'what_went_well', 'what_to_change'])]
final class SignalWeeklyRecap extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    protected $table = 'signal_weekly_recaps';
}
