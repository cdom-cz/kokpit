<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use RuntimeException;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * Replaces the package's clean action so activity records can never be pruned
 * (D-09): the audit trail is kept indefinitely. The activitylog:clean command
 * calls this action and therefore fails loudly instead of deleting anything.
 */
final class RefusingCleanActivityLogAction extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new RuntimeException(
            'Refusing to clean the activity log: activity records are kept indefinitely (D-09) and are never deleted by command or schedule.',
        );
    }
}
