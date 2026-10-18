<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

use App\Domain\Payments\Support\StripeConfig;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Throwable;

/**
 * The Stripe SDK behind the gateway interface (D-18, R11-01-1). It is the only class of the application that
 * imports a Stripe\ class.
 *
 * The API version is pinned here, so a later SDK update cannot move it silently. The client timeout (20 s) and
 * connect timeout (5 s) stay below the 60 s job timeout, with one network retry, so a slow Stripe call cannot
 * hold a worker past its job (R11-01-2, T-11-03). The idempotency key is the id of the Kokpit link row: Stripe
 * replays the stored answer for it, including a stored 5xx, which is why api_error is not retried with the same
 * key (Pitfall 7). The secret key is read from StripeConfig per call and never leaves this class (D-16); every
 * SDK exception leaves as a StripeGatewayFailure with a fixed message (D-09, T-11-01).
 */
final class StripeApiGateway implements StripeGateway
{
    public const string API_VERSION = '2026-09-30.endive';

    private const int TIMEOUT_SECONDS = 20;

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int MAX_NETWORK_RETRIES = 1;

    public function __construct(private readonly StripeConfig $config) {}

    public function createLink(PaymentLinkRequest $request): CreatedPaymentLink
    {
        $client = $this->client();

        try {
            $link = $client->paymentLinks->create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'unit_amount' => $request->amountMinor,
                        'product_data' => ['name' => $request->productName],
                    ],
                    'quantity' => 1,
                ]],
                'restrictions' => ['completed_sessions' => ['limit' => 1]],
                'metadata' => [
                    'invoice_id' => $request->invoiceId,
                    'payment_link_row' => $request->linkRowId,
                ],
                'payment_intent_data' => ['metadata' => ['invoice_id' => $request->invoiceId]],
                'payment_method_types' => ['card'],
                'after_completion' => [
                    'type' => 'hosted_confirmation',
                    'hosted_confirmation' => ['custom_message' => $request->confirmationMessage],
                ],
                'inactive_message' => $request->inactiveMessage,
            ], ['idempotency_key' => $request->idempotencyKey]);

            // A missing field reads as null on the SDK object and becomes '' here.
            $id = (string) $link->id;
            $url = (string) $link->url;
        } catch (Throwable $e) {
            throw $this->failure($e);
        }

        if ($id === '' || $url === '') {
            // A success answer without an id or an address is a provider fault; its body is not echoed.
            throw StripeGatewayFailure::apiError();
        }

        return new CreatedPaymentLink($id, $url);
    }

    public function deactivateLink(string $stripeLinkId): void
    {
        $client = $this->client();

        try {
            $client->paymentLinks->update($stripeLinkId, ['active' => false]);
        } catch (Throwable $e) {
            throw $this->failure($e);
        }
    }

    /**
     * @throws StripeGatewayFailure not_configured when the secret key is not set
     */
    private function client(): StripeClient
    {
        $key = $this->config->secretKey();

        // The SDK shares one curl client; its timeouts are set here, in the only place that builds a client.
        // A test that installed its own HTTP client through ApiRequestor is left alone.
        CurlClient::instance()->setTimeout(self::TIMEOUT_SECONDS)->setConnectTimeout(self::CONNECT_TIMEOUT_SECONDS);

        return new StripeClient([
            'api_key' => $key,
            'stripe_version' => self::API_VERSION,
            'max_network_retries' => self::MAX_NETWORK_RETRIES,
        ]);
    }

    /**
     * Maps an SDK exception to a failure class. The class decides, then the HTTP status for the answers the
     * SDK has no class for (a 409 clash on the idempotency key, a 5xx); anything else is api_error.
     */
    private function failure(Throwable $e): StripeGatewayFailure
    {
        return match (true) {
            $e instanceof ApiConnectionException => StripeGatewayFailure::connection($e),
            $e instanceof AuthenticationException, $e instanceof PermissionException => StripeGatewayFailure::authentication($e),
            $e instanceof RateLimitException => StripeGatewayFailure::rateLimited($e),
            $e instanceof IdempotencyException => StripeGatewayFailure::idempotency($e),
            $e instanceof InvalidRequestException => StripeGatewayFailure::invalidRequest($e),
            $e instanceof ApiErrorException && $e->getHttpStatus() === 409 => StripeGatewayFailure::idempotency($e),
            default => StripeGatewayFailure::apiError($e),
        };
    }
}
