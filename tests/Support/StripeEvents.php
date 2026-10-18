<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Payments\Models\PaymentLink;
use Illuminate\Support\Str;

/**
 * Stripe event bodies for the webhook tests, all fictional. Every Stripe-shaped id and the payer's address are
 * assembled at runtime from fragments (R11-01-6, Pitfall 14), so no test file holds a complete value.
 */
final class StripeEvents
{
    /**
     * A checkout.session.completed event with a paid session. `$object` is merged over the session object (recursively,
     * so one metadata key can be replaced) and `$event` over the event envelope.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function checkoutCompleted(array $object = [], array $event = []): array
    {
        return self::ofType('checkout.session.completed', $object, $event);
    }

    /**
     * A checkout.session.completed event of a paid session that came from the given link: the link's Stripe id, the
     * metadata a Kokpit link carries (the document and the link row), and the amount and currency the link offered.
     * `$overrides` is merged over the session object, `$event` over the envelope.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function checkoutFor(PaymentLink $link, array $overrides = [], array $event = []): array
    {
        return self::checkoutCompleted(self::sessionFor($link, $overrides), $event);
    }

    /**
     * The session object of a paid checkout of the given link, for a test that wraps it in another event type or
     * repeats the same session under a new event.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function sessionFor(PaymentLink $link, array $overrides = []): array
    {
        return array_replace_recursive([
            'amount_total' => $link->amount_minor,
            'currency' => strtolower($link->currency),
            'payment_link' => $link->stripe_link_id,
            'metadata' => [
                'invoice_id' => $link->invoice_id,
                'payment_link_row' => $link->getKey(),
            ],
        ], $overrides);
    }

    /**
     * A checkout.session.async_payment_succeeded event (a delayed payment method that has now been paid).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function asyncSucceeded(array $object = [], array $event = []): array
    {
        return self::ofType('checkout.session.async_payment_succeeded', $object, $event);
    }

    /**
     * An event of any type around a checkout session object (the object is the session of a card payment; a test of
     * another type replaces what it needs through `$object`).
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function ofType(string $type, array $object = [], array $event = []): array
    {
        $session = array_replace_recursive([
            'id' => self::token('cs', 'test'),
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 121000,
            'currency' => 'czk',
            'payment_intent' => self::token('pi'),
            'payment_link' => self::token('plink'),
            'metadata' => [
                'invoice_id' => (string) Str::uuid(),
                'payment_link_row' => (string) Str::uuid(),
            ],
            'customer_details' => [
                'email' => exampleEmail('jane'),
                'name' => 'Jane Example',
            ],
            'customer_email' => exampleEmail('jane'),
        ], $object);

        return array_replace_recursive([
            'id' => self::token('evt'),
            'object' => 'event',
            'api_version' => '2026-09-30.endive',
            'created' => time(),
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => $type,
            'data' => ['object' => $session],
        ], $event);
    }

    /**
     * A Stripe-shaped id from fragments, for example `evt_x1y2`.
     */
    public static function token(string ...$parts): string
    {
        return implode('_', [...$parts, Str::random(24)]);
    }
}
