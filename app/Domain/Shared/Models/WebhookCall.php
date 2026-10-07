<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\WebhookClient\Models\WebhookCall as BaseWebhookCall;

/**
 * The webhook-client call model with UUID v7 keys.
 *
 * Registered as `webhook_model` of every entry in config/webhook-client.php.
 * No webhook route exists yet; the route and payload retention belong to the
 * integrations phase.
 *
 * Admin-only in Phase 2: a Partner sees no row (DeniesPartners). Later phases
 * open it deliberately with a client-bound constraint and explicit policy grants.
 */
class WebhookCall extends BaseWebhookCall implements PartnerIsolated
{
    use DeniesPartners, HasUuids;
}
