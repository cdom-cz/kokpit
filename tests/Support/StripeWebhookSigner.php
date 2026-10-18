<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Signs and posts a Stripe webhook body in a test (D-18). The secret is the random one StripeTestConfig::configure()
 * put in the config; the HMAC is computed here by hand on purpose, so the test does not use the same code as the
 * application's check.
 */
final class StripeWebhookSigner
{
    public const string URL = '/webhooks/stripe';

    /**
     * The Stripe-Signature header of a body: `t=<timestamp>,v1=<hmac>`.
     */
    public static function header(string $body, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= time();
        $secret ??= (string) config('services.stripe.webhook_secret');

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Posts the payload as JSON with a valid header, or with the given header (a null header means a valid one).
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    public static function post(TestCase $test, array $payload, ?string $header = null): TestResponse
    {
        return self::postRaw($test, json_encode($payload, JSON_THROW_ON_ERROR), $header);
    }

    /**
     * Posts a raw body. With a null header the body is signed correctly; to post without any header use postUnsigned().
     *
     * @return TestResponse<Response>
     */
    public static function postRaw(TestCase $test, string $body, ?string $header = null): TestResponse
    {
        return $test->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header ?? self::header($body),
        ], $body);
    }

    /**
     * Posts a body with no Stripe-Signature header at all.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    public static function postUnsigned(TestCase $test, array $payload): TestResponse
    {
        return $test->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
