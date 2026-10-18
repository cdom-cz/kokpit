<?php

declare(strict_types=1);

use App\Domain\Payments\Stripe\CreatedPaymentLink;
use App\Domain\Payments\Stripe\PaymentLinkRequest;
use App\Domain\Payments\Stripe\StripeApiGateway;
use App\Domain\Payments\Stripe\StripeGateway;
use App\Domain\Payments\Stripe\StripeGatewayFailure;
use App\Domain\Payments\Support\StripeConfig;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\FakeStripeGateway;
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

    public string $lastRequestId = '';

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

        $this->lastRequestId = 'req_'.Str::random(12);

        return [$answer['body'], $answer['status'], ['request-id' => $this->lastRequestId]];
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

it('deactivates a link with one POST that sets active to false', function (): void {
    $id = stripeTestLinkId();
    $this->http->answer(['body' => json_encode(['id' => $id, 'object' => 'payment_link', 'active' => false], JSON_THROW_ON_ERROR), 'status' => 200]);

    app(StripeApiGateway::class)->deactivateLink($id);

    expect($this->http->requests)->toHaveCount(1)
        ->and($this->http->requests[0]['method'])->toBe('post')
        ->and($this->http->requests[0]['url'])->toEndWith('/v1/payment_links/'.$id)
        ->and($this->http->requests[0]['params'])->toBe(['active' => 'false'])
        ->and($this->http->header(0, 'Stripe-Version'))->toBe(StripeApiGateway::API_VERSION);
});

/**
 * The provider answers and transport failures with the failure class each must become and whether it may be
 * retried (R11-01-3, Pitfall 7).
 *
 * @return array<string, array{0: int|null, 1: string, 2: string, 3: bool}>
 */
dataset('stripe failures', [
    '401 bad key' => [401, 'invalid_request_error', 'authentication', false],
    '403 no permission' => [403, 'invalid_request_error', 'authentication', false],
    '400 rejected request' => [400, 'invalid_request_error', 'invalid_request', false],
    '429 rate limit' => [429, 'invalid_request_error', 'rate_limited', true],
    '500 provider fault' => [500, 'api_error', 'api_error', false],
    '409 idempotency clash' => [409, 'idempotency_error', 'idempotency', false],
    '400 idempotency error' => [400, 'idempotency_error', 'idempotency', false],
    'transport failure' => [null, '', 'connection', true],
]);

it('turns every provider failure into a fixed class that leaks nothing', function (?int $status, string $type, string $class, bool $retryable): void {
    $secret = (string) config('services.stripe.secret');
    $providerText = 'Provider note naming '.Str::random(10).' '.$secret;

    $canned = fn (): array|Throwable => $status === null
        ? new ApiConnectionException($providerText)
        : ['body' => json_encode(['error' => ['message' => $providerText, 'type' => $type]], JSON_THROW_ON_ERROR), 'status' => $status];

    $calls = [
        'create' => fn () => app(StripeApiGateway::class)->createLink(stripePaymentLinkRequest()),
        'deactivate' => fn () => app(StripeApiGateway::class)->deactivateLink(stripeTestLinkId()),
    ];

    foreach ($calls as $call) {
        $this->http->answer($canned());

        try {
            $call();
            $this->fail('The gateway swallowed a provider failure.');
        } catch (StripeGatewayFailure $failure) {
            expect($failure->failureClass)->toBe($class)
                ->and($failure->getMessage())->toBe('Stripe request failed: '.$class)
                ->and($failure->getMessage())->not->toContain($providerText)
                ->and($failure->getMessage())->not->toContain($secret)
                ->and($this->http->lastRequestId === '' || ! str_contains($failure->getMessage(), $this->http->lastRequestId))->toBeTrue()
                ->and($failure->isRetryable())->toBe($retryable)
                ->and($failure->getPrevious())->not->toBeNull();
        }
    }
})->with('stripe failures');

it('fails with not_configured before any request when the secret key is missing', function (): void {
    StripeTestConfig::clear();

    foreach ([
        fn () => app(StripeApiGateway::class)->createLink(stripePaymentLinkRequest()),
        fn () => app(StripeApiGateway::class)->deactivateLink(stripeTestLinkId()),
    ] as $call) {
        try {
            $call();
            $this->fail('A call without a key must fail.');
        } catch (StripeGatewayFailure $failure) {
            expect($failure->failureClass)->toBe(StripeGatewayFailure::NOT_CONFIGURED)
                ->and($failure->isRetryable())->toBeFalse();
        }
    }

    expect($this->http->requests)->toBe([]);
});

it('reports configured only when both secrets are set, without handing a value out of its answers', function (): void {
    $config = app(StripeConfig::class);

    expect($config->isConfigured())->toBeTrue()
        ->and($config->hasSecretKey())->toBeTrue()
        ->and($config->hasWebhookSecret())->toBeTrue();

    config(['services.stripe.webhook_secret' => '']);
    expect($config->isConfigured())->toBeFalse()
        ->and($config->hasSecretKey())->toBeTrue()
        ->and($config->hasWebhookSecret())->toBeFalse();

    expect(fn () => $config->webhookSecret())->toThrow(StripeGatewayFailure::class);

    StripeTestConfig::clear();
    expect($config->isConfigured())->toBeFalse()
        ->and($config->hasSecretKey())->toBeFalse()
        ->and(fn () => $config->secretKey())->toThrow(StripeGatewayFailure::class);
});

it('refuses ISK and UGX with unsupported_currency before any request', function (string $currency): void {
    try {
        new PaymentLinkRequest('key', 100, $currency, 'Invoice', 'inv', 'row', 'ok', 'ok');
        $this->fail('The currency must be refused.');
    } catch (StripeGatewayFailure $failure) {
        expect($failure->failureClass)->toBe(StripeGatewayFailure::UNSUPPORTED_CURRENCY)
            ->and($failure->isRetryable())->toBeFalse();
    }

    expect($this->http->requests)->toBe([]);
})->with(['ISK', 'UGX']);

it('retries only a lost connection or a rate limit', function (): void {
    $retryable = array_filter(
        ['not_configured', 'authentication', 'invalid_request', 'rate_limited', 'connection', 'api_error', 'idempotency', 'unsupported_currency'],
        fn (string $class): bool => StripeGatewayFailure::forClass($class)->isRetryable(),
    );

    expect(array_values($retryable))->toBe(['rate_limited', 'connection']);
});

it('resolves the gateway to the SDK implementation, and the fake replaces it', function (): void {
    expect(app(StripeGateway::class))->toBeInstanceOf(StripeApiGateway::class);

    $fake = FakeStripeGateway::install();

    expect(app(StripeGateway::class))->toBe($fake);
});

it('makes the fake return the same link for a repeated idempotency key', function (): void {
    $fake = FakeStripeGateway::install();
    $request = stripePaymentLinkRequest();

    $first = $fake->createLink($request);
    $again = $fake->createLink($request);
    $other = $fake->createLink(stripePaymentLinkRequest());

    expect($again)->toEqual($first)
        ->and($other->id)->not->toBe($first->id)
        ->and($fake->created())->toHaveCount(2)
        ->and($fake->created()[0])->toBe($request)
        ->and(parse_url($first->url, PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($first->url, PHP_URL_HOST))->toBe(implode('.', ['buy', 'stripe', 'com']));
});

it('makes the fake record deactivations and raise a configured failure once', function (): void {
    $fake = FakeStripeGateway::install();
    $id = stripeTestLinkId();

    $fake->deactivateLink($id);
    expect($fake->deactivated())->toBe([$id]);

    $fake->failNextCreate(StripeGatewayFailure::RATE_LIMITED);
    try {
        $fake->createLink(stripePaymentLinkRequest());
        $this->fail('The configured failure was not raised.');
    } catch (StripeGatewayFailure $failure) {
        expect($failure->failureClass)->toBe(StripeGatewayFailure::RATE_LIMITED)
            ->and($failure->isRetryable())->toBeTrue();
    }
    expect($fake->created())->toBe([])
        ->and($fake->createLink(stripePaymentLinkRequest()))->toBeInstanceOf(CreatedPaymentLink::class);

    $fake->failNextDeactivate(StripeGatewayFailure::CONNECTION);
    expect(fn () => $fake->deactivateLink($id))->toThrow(StripeGatewayFailure::class);
    $fake->deactivateLink($id);
    expect($fake->deactivated())->toBe([$id, $id]);
});

it('imports a Stripe SDK class nowhere outside app/Domain/Payments/Stripe', function (): void {
    $root = base_path();
    $offenders = [];

    foreach (['app', 'bootstrap', 'config', 'routes', 'database'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/app/Domain/Payments/Stripe/')) {
                continue;
            }

            // A use statement or a fully qualified \Stripe\ name; App\Domain\Payments\Stripe\ does not match.
            $source = (string) file_get_contents($path);
            if (preg_match('/^\\s*use\\s+\\\\?Stripe\\\\/m', $source) === 1
                || preg_match('/(?<![\\w\\\\])\\\\Stripe\\\\/', $source) === 1) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
