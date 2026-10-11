<?php

declare(strict_types=1);

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * The deep-work blocks of one day. The row is created lazily on the first tick and freezes `planned`
 * from the settings of that moment; `completed` stays within 0..planned.
 *
 * @property string $id
 * @property string $user_id
 * @property string $for_date
 * @property int $planned
 * @property int $completed
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['for_date', 'planned', 'completed'])]
final class SignalDeepWorkDay extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    protected $table = 'signal_deep_work_days';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned' => 'integer',
            'completed' => 'integer',
        ];
    }
}
