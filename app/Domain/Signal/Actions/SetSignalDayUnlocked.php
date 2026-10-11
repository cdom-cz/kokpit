<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalDayOverride;
use App\Domain\Signal\Support\SignalCalendar;
use App\Domain\Signal\Support\SignalLock;
use Illuminate\Support\Facades\DB;

/**
 * Unlocks a past day for full editing, or locks it again. Today and the future are never locked, so
 * asking for them changes nothing. Both directions are idempotent.
 */
final class SetSignalDayUnlocked
{
    use AuthorizesSignal;

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, string $forDate, bool $unlocked): void
    {
        $this->authorizeActor($actor);

        if (! SignalCalendar::isValidDay($forDate)) {
            throw SignalRuleViolation::because('invalid_date');
        }

        if ($forDate >= SignalCalendar::today()) {
            return;
        }

        DB::transaction(function () use ($actor, $forDate, $unlocked): void {
            SignalLock::take((string) $actor->getKey(), $forDate, 'lock');

            $existing = SignalDayOverride::query()->where('for_date', $forDate)->first();

            if ($unlocked && $existing === null) {
                SignalDayOverride::query()->create(['for_date' => $forDate]);
            }

            if (! $unlocked && $existing !== null) {
                $existing->delete();
            }
        });
    }
}
