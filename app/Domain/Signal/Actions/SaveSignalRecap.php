<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use App\Domain\Signal\Support\SignalRules;
use Illuminate\Support\Facades\DB;

/**
 * Saves the recap of a week: one row per user and week, created on the first save and overwritten
 * afterwards. A recap can be edited at any time, also for a past week. Each text is trimmed and holds at
 * most SignalRules::MAX_RECAP_LENGTH characters.
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

        $whatWentWell = trim($whatWentWell);
        $whatToChange = trim($whatToChange);

        if (mb_strlen($whatWentWell) > SignalRules::MAX_RECAP_LENGTH || mb_strlen($whatToChange) > SignalRules::MAX_RECAP_LENGTH) {
            throw SignalRuleViolation::because('recap_too_long', ['max' => SignalRules::MAX_RECAP_LENGTH]);
        }

        return DB::transaction(function () use ($actor, $weekStart, $whatWentWell, $whatToChange): SignalWeeklyRecap {
            SignalLock::take((string) $actor->getKey(), $weekStart, 'recap');

            $recap = SignalWeeklyRecap::query()->where('week_start', $weekStart)->first() ?? new SignalWeeklyRecap(['week_start' => $weekStart]);
            $recap->what_went_well = $whatWentWell;
            $recap->what_to_change = $whatToChange;
            $recap->save();

            return $recap;
        });
    }
}
