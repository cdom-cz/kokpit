<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\WebhookClient\Models\WebhookCall as BaseWebhookCall;

/**
 * The webhook-client call model with UUID v7 keys.
 *
 * Registered as `webhook_model` of every entry in config/webhook-client.php.
 * No webhook route exists yet; the route and payload retention belong to the
 * integrations phase.
 */
class WebhookCall extends BaseWebhookCall
{
    use HasUuids;
}
