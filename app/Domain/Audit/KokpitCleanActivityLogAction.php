<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Shared\Auth\PartnerContext;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * The clean action of config activitylog.actions.clean_log: it deletes the
 * activity rows older than activitylog.clean_after_days (365). Its only caller is
 * the scheduled activitylog:clean (kokpit-activity-log-prune in routes/console.php).
 *
 * It runs the delete as system. A console run has no signed-in user, and the
 * fail-closed PartnerScope on the Activity model would otherwise reduce the
 * delete to `WHERE 1 = 0`, which removes nothing and still reports success
 * (phase 02 review WR-04). runAsSystem() restores the previous flag afterwards,
 * even when the delete throws.
 */
final class KokpitCleanActivityLogAction extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        return app(PartnerContext::class)->runAsSystem(
            fn (): int => parent::execute($maxAgeInDays, $logName),
        );
    }
}
