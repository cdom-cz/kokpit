<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use Illuminate\Support\Facades\DB;

/**
 * Saves the recap of a week: one row per user and week, created on the first save and overwritten
 * afterwards. A recap can be edited at any time, also for a past week.
 */
final class SaveSignalRecap
{
    use AuthorizesSignal;

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $weekStart, string $whatWentWell, string $whatToChange): SignalWeeklyRecap
    {
        $this->authorizeActor($actor);

        if (! SignalCalendar::isWeekStart($weekStart)) {
            throw SignalRuleViolation::because('invalid_week');
        }

        return DB::transaction(function () use ($actor, $weekStart, $whatWentWell, $whatToChange): SignalWeeklyRecap {
            SignalLock::take((string) $actor->getKey(), $weekStart, 'recap');

            $recap = SignalWeeklyRecap::query()->where('week_start', $weekStart)->first() ?? new SignalWeeklyRecap(['week_start' => $weekStart]);
            $recap->what_went_well = trim($whatWentWell);
            $recap->what_to_change = trim($whatToChange);
            $recap->save();

            return $recap;
        });
    }
}
