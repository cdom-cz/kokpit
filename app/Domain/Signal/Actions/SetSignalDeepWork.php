<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalDeepWorkDay;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use App\Domain\Signal\Support\SignalRules;
use Illuminate\Support\Facades\DB;

/**
 * Sets the completed deep-work blocks of a day. Allowed on today and past days, even locked ones
 * (like ticking a task), never in the future. The day row is created on the first write and freezes
 * `planned` from the settings of that moment; later writes change only `completed`, clamped to 0..planned.
 */
final class SetSignalDeepWork
{
    use AuthorizesSignal;

    public function __construct(private readonly SignalDayReader $reader) {}

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $forDate, int $completed): SignalDeepWorkDay
    {
        $this->authorizeActor($actor);

        if (! SignalCalendar::isValidDay($forDate)) {
            throw SignalRuleViolation::because('invalid_date');
        }

        if (! SignalRules::canToggleDone($forDate, SignalCalendar::today())) {
            throw SignalRuleViolation::because('future_day');
        }

        return DB::transaction(function () use ($actor, $forDate, $completed): SignalDeepWorkDay {
            SignalLock::take((string) $actor->getKey(), $forDate, 'deep');

            $row = SignalDeepWorkDay::query()->where('for_date', $forDate)->lockForUpdate()->first();

            if ($row === null) {
                $settings = $this->reader->blockSettings();
                $planned = SignalRules::plannedBlocks($forDate, $settings['weekday'], $settings['weekend']);
                $row = new SignalDeepWorkDay(['for_date' => $forDate, 'planned' => $planned, 'completed' => 0]);
            }

            $row->completed = SignalRules::clampCompleted($completed, $row->planned);
            $row->save();

            return $row;
        });
    }
}
