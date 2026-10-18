<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Auth\PartnerIsolated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\WebhookClient\Models\WebhookCall as BaseWebhookCall;
use Spatie\WebhookClient\WebhookConfig;

/**
 * The webhook-client call model with UUID v7 keys.
 *
 * Registered as `webhook_model` of every entry in config/webhook-client.php. The one entry is the Stripe event
 * destination (POST /webhooks/stripe, Phase 11): a handled event is stored once under its unique Stripe event id
 * ({@see self::storeStripeEvent()}) and marked processed or failed by ProcessStripeWebhook. Retention of the
 * processed rows (90 days, D-17) belongs to the pruning of plan 11-14.
 *
 * Admin-only in Phase 2: a Partner sees no row (DeniesPartners). Later phases open it deliberately with a client-bound
 * constraint and explicit policy grants.
 *
 * @property string|null $event_id Stripe's text event id; unique together with the name
 * @property string|null $event_name the Stripe event type
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface|null $failed_at
 * @property string|null $failure_class a fixed class, never an exception text
 */
class WebhookCall extends BaseWebhookCall implements PartnerIsolated
{
    use DeniesPartners, HasUuids;

    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
        'attachments' => 'array',
        'exception' => 'array',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Stores a Stripe event once (D-12, R11-04-4) and says whether this call stored it.
     *
     * The insert is `INSERT ... ON CONFLICT DO NOTHING` against the partial unique index on (name, event_id): a
     * repeated delivery inserts nothing and raises nothing, so it cannot abort an enclosing PostgreSQL transaction
     * the way a caught unique violation would. The stored row is then read back by name and event id. The model
     * denies Partners, so both steps run as system.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: WebhookCall, 1: bool} the row and whether it was inserted now
     */
    public static function storeStripeEvent(WebhookConfig $config, string $eventId, string $eventName, array $payload): array
    {
        return app(PartnerContext::class)->runAsSystem(static function () use ($config, $eventId, $eventName, $payload): array {
            $now = now();

            $inserted = static::query()->insertOrIgnore([
                'name' => $config->name,
                'url' => route('webhooks.stripe'),
                'headers' => null,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'attachments' => null,
                'exception' => null,
                'event_id' => $eventId,
                'event_name' => $eventName,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;

            $call = static::query()
                ->where('name', $config->name)
                ->where('event_id', $eventId)
                ->firstOrFail();

            return [$call, $inserted];
        });
    }
}
