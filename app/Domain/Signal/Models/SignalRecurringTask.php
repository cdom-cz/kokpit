<?php

declare(strict_types=1);

namespace App\Domain\Signal\Models;

use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Signal\Casts\WeekdaysCast;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Support\SignalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A recurring template of the planner: it yields one task on each day whose weekday is in its list.
 * Instances are materialized lazily for today only; later days show the template as a read-only shadow.
 *
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property SignalCategory $category
 * @property list<int> $weekdays 0 (Monday) ... 6 (Sunday), sorted, without duplicates
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['title', 'category', 'weekdays', 'active'])]
final class SignalRecurringTask extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    protected $table = 'signal_recurring_tasks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => SignalCategory::class,
            'weekdays' => WeekdaysCast::class,
            'active' => 'boolean',
        ];
    }

    /**
     * Active templates that fire on the weekday of the given day.
     *
     * @param  Builder<SignalRecurringTask>  $query
     */
    public function scopeFiringOn(Builder $query, string $day): void
    {
        $query->where('active', true)
            ->whereRaw('weekdays @> ARRAY[?]::smallint[]', [SignalCalendar::weekdayIndex($day)]);
    }
}
