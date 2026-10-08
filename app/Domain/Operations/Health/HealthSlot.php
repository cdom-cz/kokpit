<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health;

use Filament\Support\Contracts\HasLabel;

/**
 * The fixed slots of the System page (D-13). Every case needs an indicator in
 * the registry; later phases fill the placeholder slots through
 * HealthIndicatorRegistry::replace().
 */
enum HealthSlot: string implements HasLabel
{
    case FailedJobs = 'failed_jobs';
    case OldestPendingJob = 'oldest_pending_job';
    case SchedulerHeartbeat = 'scheduler_heartbeat';
    case LastRateDate = 'last_rate_date';
    case UnprocessedWebhooks = 'unprocessed_webhooks';
    case UnsentInvoiceEmails = 'unsent_invoice_emails';

    public function getLabel(): string
    {
        return __('enums.health_slot.'.$this->value);
    }
}
