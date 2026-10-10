<?php

declare(strict_types=1);

namespace App\Domain\Signal\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use App\Domain\Signal\Models\SignalSetting;
use Illuminate\Support\Facades\DB;

/**
 * Saves the default number of deep-work blocks for a weekday and for a weekend day (0..12 each).
 * It changes the days that have no stored row yet; a stored day keeps its frozen `planned`.
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

        return DB::transaction(function () use ($weekdayBlocks, $weekendBlocks): SignalSetting {
            $setting = SignalSetting::query()->lockForUpdate()->first() ?? new SignalSetting;
            $setting->deep_work_weekday_blocks = $weekdayBlocks;
            $setting->deep_work_weekend_blocks = $weekendBlocks;
            $setting->save();

            return $setting;
        });
    }
}
