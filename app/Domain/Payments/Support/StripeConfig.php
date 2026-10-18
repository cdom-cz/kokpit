<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use App\Domain\Payments\Stripe\StripeGatewayFailure;

/**
 * Answers whether the two Stripe secrets are set, without showing them (D-16).
 *
 * Both values come only from the environment through config/services.php and are read at call time. The two
 * value getters exist for the gateway (the API key) and the webhook signature check (the signing secret);
 * nothing else hands a value to a view, a log or the settings. A link is created only when Stripe is fully
 * configured, so isConfigured() needs both (UI-SPEC U-4).
 */
final readonly class StripeConfig
{
    public function isConfigured(): bool
    {
        return $this->hasSecretKey() && $this->hasWebhookSecret();
    }

    public function hasSecretKey(): bool
    {
        return $this->value('secret') !== '';
    }

    public function hasWebhookSecret(): bool
    {
        return $this->value('webhook_secret') !== '';
    }

    /**
     * @throws StripeGatewayFailure not_configured when the key is not set
     */
    public function secretKey(): string
    {
        return $this->required('secret');
    }

    /**
     * @throws StripeGatewayFailure not_configured when the secret is not set
     */
    public function webhookSecret(): string
    {
        return $this->required('webhook_secret');
    }

    private function required(string $key): string
    {
        $value = $this->value($key);

        return $value !== '' ? $value : throw StripeGatewayFailure::notConfigured();
    }

    private function value(string $key): string
    {
        $value = config('services.stripe.'.$key);

        return is_string($value) ? trim($value) : '';
    }
}
