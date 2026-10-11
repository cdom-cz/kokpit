<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalSetting;
use App\Domain\Signal\Support\SignalLock;
use Illuminate\Support\Facades\DB;

/**
 * Saves the default number of deep-work blocks for a weekday and for a weekend day (0..12 each).
 * It changes the days that have no stored row yet; a stored day keeps its frozen `planned`. The save runs
 * under the per-user lock of the settings, so two first saves cannot both insert the row.
 */
final class SaveSignalSettings
{
    use AuthorizesSignal;

    /**
     * @throws SignalRuleViolation
     */
    public function handle(User $actor, int $weekdayBlocks, int $weekendBlocks): SignalSetting
    {
        $this->authorizeActor($actor);

        foreach ([$weekdayBlocks, $weekendBlocks] as $blocks) {
            if ($blocks < 0 || $blocks > SignalSetting::MAX_BLOCKS) {
                throw SignalRuleViolation::because('blocks_range', ['max' => SignalSetting::MAX_BLOCKS]);
            }
        }

        return DB::transaction(function () use ($actor, $weekdayBlocks, $weekendBlocks): SignalSetting {
            // One row per user. The first save finds no row to lock (PostgreSQL has no gap lock), so the
            // per-user advisory lock is what makes two parallel first saves run one after the other.
            SignalLock::take((string) $actor->getKey(), 'settings');

            $setting = SignalSetting::query()->first() ?? new SignalSetting;
            $setting->deep_work_weekday_blocks = $weekdayBlocks;
            $setting->deep_work_weekend_blocks = $weekendBlocks;
            $setting->save();

            return $setting;
        });
    }
}
