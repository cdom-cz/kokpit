<?php

declare(strict_types=1);

use App\Domain\Payments\Stripe\CreatedPaymentLink;
use App\Domain\Payments\Stripe\PaymentLinkRequest;
use App\Domain\Payments\Stripe\StripeApiGateway;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\StripeTestConfig;

/*
 * The Stripe gateway proven without the network (D-18): a recording HTTP client takes the place of the SDK's
 * curl client and answers with canned JSON, so a test reads exactly what would have left the application.
 * Every key, link id and address is assembled at runtime (R11-01-6).
 */

/**
 * Records every request the SDK makes and answers with the next canned response.
 */
final class RecordingStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>, retries: int|null}> */
    public array $requests = [];

    /** @var list<array{body: string, status: int}|Throwable> */
    private array $answers = [];

    /**
     * @param  array{body: string, status: int}|Throwable  $answer
     */
    public function answer(array|Throwable $answer): self
    {
        $this->answers[] = $answer;

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $headers,
            'params' => $params,
            'retries' => $maxNetworkRetries,
        ];

        $answer = array_shift($this->answers) ?? throw new LogicException('No canned Stripe answer left.');
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return [$answer['body'], $answer['status'], ['request-id' => 'req_'.Str::random(12)]];
    }

    public function header(int $index, string $name): ?string
    {
        foreach ($this->requests[$index]['headers'] as $line) {
            if (str_starts_with(strtolower($line), strtolower($name).':')) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }
}

/** A fictional link id, assembled at runtime. */
function stripeTestLinkId(): string
{
    return implode('_', ['plink', Str::random(24)]);
}

/** A fictional hosted link address, assembled at runtime. */
function stripeTestLinkUrl(): string
{
    return 'https://'.implode('.', ['buy', 'stripe', 'com']).'/'.Str::random(20);
}

/**
 * The canned success answer of a payment link creation.
 *
 * @return array{body: string, status: int}
 */
function stripeCreatedAnswer(string $id, string $url): array
{
    return ['body' => json_encode(['id' => $id, 'object' => 'payment_link', 'active' => true, 'url' => $url], JSON_THROW_ON_ERROR), 'status' => 200];
}

function stripePaymentLinkRequest(?string $rowId = null): PaymentLinkRequest
{
    return new PaymentLinkRequest(
        idempotencyKey: $rowId ??= (string) Str::uuid7(),
        amountMinor: 1_250_000,
        currency: 'CZK',
        productName: 'Faktura 2026-0001',
        invoiceId: (string) Str::uuid7(),
        linkRowId: $rowId,
        confirmationMessage: 'Thank you, the payment was received.',
        inactiveMessage: 'This payment link is no longer active.',
    );
}

beforeEach(function (): void {
    StripeTestConfig::configure();
    $this->http = new RecordingStripeHttpClient;
    ApiRequestor::setHttpClient($this->http);
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
    StripeTestConfig::clear();
});

it('sends one form POST to payment_links with the row idempotency key and the pinned API version', function (): void {
    $id = stripeTestLinkId();
    $url = stripeTestLinkUrl();
    $this->http->answer(stripeCreatedAnswer($id, $url));

    $request = stripePaymentLinkRequest();
    $created = app(StripeApiGateway::class)->createLink($request);

    expect($this->http->requests)->toHaveCount(1);
    $sent = $this->http->requests[0];

    expect($sent['method'])->toBe('post')
        ->and($sent['url'])->toEndWith('/v1/payment_links')
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe($request->linkRowId)
        ->and($this->http->header(0, 'Stripe-Version'))->toBe(StripeApiGateway::API_VERSION)
        ->and($this->http->header(0, 'Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and($this->http->header(0, 'Authorization'))->toBe('Bearer '.config('services.stripe.secret'))
        ->and($sent['retries'])->toBe(1);

    expect($sent['params'])
        ->toHaveKey('line_items.0.price_data.currency', 'czk')
        ->toHaveKey('line_items.0.price_data.unit_amount', 1_250_000)
        ->toHaveKey('line_items.0.price_data.product_data.name', 'Faktura 2026-0001')
        ->toHaveKey('line_items.0.quantity', 1)
        ->toHaveKey('restrictions.completed_sessions.limit', 1)
        ->toHaveKey('metadata.invoice_id', $request->invoiceId)
        ->toHaveKey('metadata.payment_link_row', $request->linkRowId)
        ->toHaveKey('payment_intent_data.metadata.invoice_id', $request->invoiceId)
        ->toHaveKey('payment_method_types.0', 'card')
        ->toHaveKey('after_completion.type', 'hosted_confirmation')
        ->toHaveKey('after_completion.hosted_confirmation.custom_message', $request->confirmationMessage)
        ->toHaveKey('inactive_message', $request->inactiveMessage);

    expect($created)->toEqual(new CreatedPaymentLink($id, $url));
});

it('refuses a non-positive amount', function (int $amount): void {
    new PaymentLinkRequest('key', $amount, 'CZK', 'Invoice', 'inv', 'row', 'ok', 'ok');
})->throws(InvalidArgumentException::class)->with([0, -5]);

it('refuses a currency that is not three upper-case letters', function (string $currency): void {
    new PaymentLinkRequest('key', 100, $currency, 'Invoice', 'inv', 'row', 'ok', 'ok');
})->throws(InvalidArgumentException::class)->with(['czk', 'CZ', 'CZKK', '']);

it('refuses a message above 500 characters', function (): void {
    new PaymentLinkRequest('key', 100, 'CZK', 'Invoice', 'inv', 'row', str_repeat('x', 501), 'ok');
})->throws(InvalidArgumentException::class);

it('takes a message of exactly 500 characters', function (): void {
    $request = new PaymentLinkRequest('key', 100, 'CZK', 'Invoice', 'inv', 'row', str_repeat('x', 500), str_repeat('y', 500));

    expect(mb_strlen($request->confirmationMessage))->toBe(500);
});
