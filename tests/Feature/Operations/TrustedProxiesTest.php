<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Behind the Zerops balancer TLS ends at the proxy, so the application only
 * knows the original scheme from the forwarded headers (research Pitfall 19).
 * Without the trust configuration a link would be generated as http://.
 */

beforeEach(function (): void {
    Route::get('/_proxy-probe', fn () => response()->json([
        'url' => url('/example'),
        'secure' => request()->isSecure(),
    ]));
});

/**
 * @param  array<string, string>  $server
 * @return array{url: string, secure: bool}
 */
function probeThroughProxy(array $server): array
{
    // An absolute http URI: the test base URL is https, which would make every request secure.
    $response = test()->call('GET', 'http://localhost/_proxy-probe', [], [], [], $server);

    /** @var array{url: string, secure: bool} $body */
    $body = $response->json();

    return $body;
}

it('generates https URLs for a request the balancer forwarded as HTTPS', function (): void {
    $body = probeThroughProxy([
        'REMOTE_ADDR' => '10.20.30.40',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ]);

    expect($body['secure'])->toBeTrue()
        ->and($body['url'])->toStartWith('https://');
});

it('generates http URLs when the request carries no forwarded protocol', function (): void {
    $body = probeThroughProxy(['REMOTE_ADDR' => '10.20.30.40']);

    expect($body['secure'])->toBeFalse()
        ->and($body['url'])->toStartWith('http://');
});

it('ignores a forwarded host: generated URLs keep the host of the request', function (): void {
    $body = probeThroughProxy([
        'REMOTE_ADDR' => '10.20.30.40',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'attacker.example.com',
    ]);

    expect($body['url'])->toStartWith('https://localhost/')
        ->and($body['url'])->not->toContain('attacker.example.com');
});

it('trusts only the forwarded for, port and proto headers', function (): void {
    expect(file_get_contents(base_path('bootstrap/app.php')))
        ->toContain('Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO')
        ->not->toContain('HEADER_X_FORWARDED_HOST')
        ->not->toContain('HEADER_X_FORWARDED_AWS_ELB');
});
