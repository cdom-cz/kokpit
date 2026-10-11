<?php

declare(strict_types=1);

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * The planner settings of a user: the default number of deep-work blocks on a weekday and at a weekend.
 * A user without a row works with the defaults (3 and 0).
 *
 * @property string $id
 * @property string $user_id
 * @property int $deep_work_weekday_blocks
 * @property int $deep_work_weekend_blocks
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['deep_work_weekday_blocks', 'deep_work_weekend_blocks'])]
final class SignalSetting extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    public const int DEFAULT_WEEKDAY_BLOCKS = 3;

    public const int DEFAULT_WEEKEND_BLOCKS = 0;

    public const int MAX_BLOCKS = 12;

    protected $table = 'signal_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deep_work_weekday_blocks' => 'integer',
            'deep_work_weekend_blocks' => 'integer',
        ];
    }
}
