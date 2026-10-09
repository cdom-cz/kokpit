<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * The single write path of the activity log (config activitylog.actions.log_activity).
 *
 * Model events and manual activity() calls both end in execute(), which calls
 * beforeActivityLogged() before saving, so the source label is set on every
 * row on the server side (D-08).
 */
final class KokpitLogActivityAction extends LogActivityAction
{
    protected function beforeActivityLogged(Model $activity): void
    {
        // The parent forwards to the subject's own hook first.
        parent::beforeActivityLogged($activity);

        $activity->setAttribute('source', app(ActivitySource::class)->current()->value);
    }
}
