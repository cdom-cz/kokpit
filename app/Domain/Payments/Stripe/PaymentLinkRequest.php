<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

use InvalidArgumentException;

/**
 * Everything Stripe needs to create one single-use payment link for one invoice amount.
 *
 * The amount is in minor units of the currency (ISO exponent), the currency is upper-case ISO 4217 here and
 * lower-case on the wire. Stripe's two-decimal convention for ISK and UGX differs from the ISO exponent, so
 * those two are refused before any call (R11-01-4); an amount below Stripe's minimum comes back from Stripe as
 * invalid_request. The two messages are Stripe's hosted confirmation and inactive texts, 500 characters at most.
 */
final readonly class PaymentLinkRequest
{
    public const int MESSAGE_LIMIT = 500;

    /** @var list<string> */
    public const array UNSUPPORTED_CURRENCIES = ['ISK', 'UGX'];

    /**
     * @throws InvalidArgumentException when a value cannot be sent to Stripe at all
     * @throws StripeGatewayFailure unsupported_currency for ISK and UGX
     */
    public function __construct(
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public string $productName,
        public string $invoiceId,
        public string $linkRowId,
        public string $confirmationMessage,
        public string $inactiveMessage,
    ) {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('The payment link amount must be positive.');
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('The payment link currency must be three upper-case letters.');
        }

        if (in_array($currency, self::UNSUPPORTED_CURRENCIES, true)) {
            throw StripeGatewayFailure::unsupportedCurrency();
        }

        foreach ([$confirmationMessage, $inactiveMessage] as $message) {
            if (mb_strlen($message) > self::MESSAGE_LIMIT) {
                throw new InvalidArgumentException('A payment link message is at most '.self::MESSAGE_LIMIT.' characters.');
            }
        }
    }
}
