<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

use App\Domain\Payments\Support\StripeConfig;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;

/**
 * Checks the Stripe-Signature header of a webhook call on the raw body (D-15, R11-04-2).
 *
 * The check is the SDK's own and nothing else: it handles several v1 signatures during a secret rotation, ignores the
 * test scheme, compares in constant time and refuses a timestamp older than the tolerance. It fails closed: without
 * a signing secret in the environment no call is valid, so an unconfigured instance never processes an unsigned body.
 * This class lives here because the Stripe SDK namespace is confined to this directory.
 */
final readonly class StripeSignatureValidator implements SignatureValidator
{
    /** The tolerance of the signed timestamp in seconds, the SDK default. */
    public const int TOLERANCE_SECONDS = 300;

    public function __construct(private StripeConfig $stripe) {}

    public function isValid(Request $request, WebhookConfig $config): bool
    {
        if (! $this->stripe->hasWebhookSecret()) {
            return false;
        }

        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $this->stripe->webhookSecret(),
                self::TOLERANCE_SECONDS,
            );
        } catch (SignatureVerificationException) {
            return false;
        }

        return true;
    }
}
