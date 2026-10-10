<?php

declare(strict_types=1);

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * A past day the user unlocked for full editing; the presence of the row is the whole fact.
 *
 * @property string $id
 * @property string $user_id
 * @property string $for_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['for_date'])]
final class SignalDayOverride extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    protected $table = 'signal_day_overrides';
}
