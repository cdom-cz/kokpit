<?php

declare(strict_types=1);

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * One of the (at most three) goals of a week. A week is identified by its Monday.
 *
 * @property string $id
 * @property string $user_id
 * @property string $week_start
 * @property string $title
 * @property int $position 1..3
 * @property bool $is_done
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['week_start', 'title', 'position', 'is_done'])]
final class SignalWeeklyGoal extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    protected $table = 'signal_weekly_goals';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_done' => 'boolean',
        ];
    }
}
