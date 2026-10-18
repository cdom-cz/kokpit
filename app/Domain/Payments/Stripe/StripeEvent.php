<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the application reads from a stored Stripe event (R11-13-1, research "Reading a verified event", Pitfall 6).
 *
 * The stored payload is verified but external, so it is read defensively and never through SDK objects: only the few
 * fields below are taken, each one checked, and anything malformed becomes null. The amount is the session's
 * `amount_total` and the currency its `currency`, which are in the currency of the integration (the one the link
 * offered), never the payer's presentment figures. The two metadata ids are kept only when they are UUIDs.
 *
 * A refund event (`refund.created`, R11-16-3) carries a refund object: its `id`, positive `amount` and `currency` become
 * the refund fields, and its `payment_intent` is the payment intent field; the object's id is then not a session id.
 */
final readonly class StripeEvent
{
    public function __construct(
        public ?string $id,
        public ?string $type,
        public ?CarbonImmutable $created,
        public ?string $sessionId,
        public ?string $paymentStatus,
        public ?string $paymentIntentId,
        public ?string $paymentLinkId,
        public ?int $amountMinor,
        public ?string $currency,
        public ?string $invoiceId,
        public ?string $paymentLinkRow,
        public ?string $refundId = null,
        public ?int $refundAmountMinor = null,
        public ?string $refundCurrency = null,
    ) {}

    /**
     * Reads the stored payload of a webhook row.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $object = is_array($payload['data'] ?? null) && is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        $amount = $object['amount_total'] ?? null;
        $currency = $object['currency'] ?? null;
        $created = $payload['created'] ?? null;
        $isRefund = ($object['object'] ?? null) === 'refund';
        $refundAmount = $object['amount'] ?? null;

        return new self(
            id: self::text($payload['id'] ?? null),
            type: self::text($payload['type'] ?? null),
            created: is_int($created) && $created > 0 ? self::timestamp($created) : null,
            sessionId: $isRefund ? null : self::text($object['id'] ?? null),
            paymentStatus: self::text($object['payment_status'] ?? null),
            paymentIntentId: self::text($object['payment_intent'] ?? null),
            paymentLinkId: self::text($object['payment_link'] ?? null),
            amountMinor: is_int($amount) && $amount > 0 ? $amount : null,
            currency: self::currency($currency),
            invoiceId: self::uuid($metadata['invoice_id'] ?? null),
            paymentLinkRow: self::uuid($metadata['payment_link_row'] ?? null),
            refundId: $isRefund ? self::text($object['id'] ?? null) : null,
            refundAmountMinor: $isRefund && is_int($refundAmount) && $refundAmount > 0 ? $refundAmount : null,
            refundCurrency: $isRefund ? self::currency($currency) : null,
        );
    }

    /**
     * Whether Stripe reports the session as paid. An unpaid session (a delayed method not yet confirmed) waits for its
     * async event.
     */
    public function isPaid(): bool
    {
        return $this->paymentStatus === 'paid';
    }

    private static function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/', $value) === 1 ? strtoupper($value) : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 ? $value : null;
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    private static function timestamp(int $seconds): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromTimestamp($seconds);
        } catch (Throwable) {
            return null;
        }
    }
}
