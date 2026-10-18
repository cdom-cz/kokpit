<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Payments\Support\StripeConfig;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Shared\Auth\PartnerContext;

/**
 * Whether Stripe is configured (D-16, UI-SPEC Surface K and U-14).
 *
 * The two secrets live only in the environment. This indicator asks {@see StripeConfig} two yes-or-no questions and
 * never reads a value, so neither a key nor a prefix of one can reach the System page (T-11-49). Both secrets set is
 * Ok. Only one of the two is Error whatever the online payments setting says, because a half configuration breaks
 * either the links or the signature check. Neither set is Error when online payments are on (links would be promised
 * and never created) and Ok when they are off (nothing needs Stripe).
 */
final readonly class StripeConfigIndicator implements HealthIndicator
{
    public function slot(): HealthSlot
    {
        return HealthSlot::Stripe;
    }

    public function check(): HealthResult
    {
        $config = app(StripeConfig::class);
        $hasKey = $config->hasSecretKey();
        $hasWebhookSecret = $config->hasWebhookSecret();

        if ($hasKey && $hasWebhookSecret) {
            return new HealthResult(HealthStatus::Ok, __('kokpit.system.stripe.value_set'), __('kokpit.system.stripe.configured'));
        }

        $unset = __('kokpit.system.stripe.value_unset');

        if ($hasKey) {
            return new HealthResult(HealthStatus::Error, $unset, __('kokpit.system.stripe.missing_webhook_secret'));
        }

        if ($hasWebhookSecret) {
            return new HealthResult(HealthStatus::Error, $unset, __('kokpit.system.stripe.missing_secret_key'));
        }

        // The settings model is Admin-only and the registry may also run outside a request, so the read is explicit.
        $enabled = app(PartnerContext::class)->runAsSystem(
            static fn (): bool => app(PaymentSettings::class)->online_payments_enabled,
        );

        return $enabled
            ? new HealthResult(HealthStatus::Error, $unset, __('kokpit.system.stripe.missing_enabled'))
            : new HealthResult(HealthStatus::Ok, $unset, __('kokpit.system.stripe.disabled'));
    }
}
