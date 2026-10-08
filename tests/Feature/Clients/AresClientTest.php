<?php

declare(strict_types=1);

use App\Domain\Clients\Ares\AresClient;
use App\Domain\Clients\Ares\AresFailure;
use App\Domain\Clients\Ares\AresLookupFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Canary;
use Tests\Support\FictionalCompanyId;

/*
 * The ARES adapter (CL-04, D-08): id validation before any request, the status
 * mapping, bounded retry, the cache and the per-user limiter. Every response is an
 * invented payload; Http::preventStrayRequests() (TestCase) blocks the live service.
 */

/**
 * @param  array<string, mixed>  $seat  replaces the whole `sidlo` object
 * @return array<string, mixed>
 */
function clientPayload(string $number, ?array $seat = null): array
{
    return [
        'ico' => $number,
        'obchodniJmeno' => 'Example Registry Ltd.',
        'dic' => 'CZ'.$number,
        'sidlo' => $seat ?? [
            'kodStatu' => 'CZ',
            'nazevObce' => 'Example Town',
            'nazevCastiObce' => 'Example Quarter',
            'nazevUlice' => 'Example Street',
            'cisloDomovni' => 12,
            'cisloOrientacni' => 3,
            'psc' => 1100,
            'textovaAdresa' => 'Example Street 12/3, Example Quarter, 01100 Example Town',
        ],
    ];
}

/**
 * Runs the lookup and returns the failure it throws.
 */
function lookupFailure(string $number): AresFailure
{
    try {
        app(AresClient::class)->lookup($number);
    } catch (AresLookupFailed $failure) {
        return $failure->failure;
    }

    throw new RuntimeException('The lookup did not fail.');
}

beforeEach(function (): void {
    Sleep::fake();
    Cache::flush();
    $this->actingAs(Canary::admin());
});

it('refuses a bad id before any request', function (string $id): void {
    Http::fake();

    expect(lookupFailure($id))->toBe(AresFailure::InvalidId);

    Http::assertNothingSent();
})->with(['checksum-invalid placeholder' => '12345678', 'letters' => 'ABC', 'empty' => '', 'path trick' => '../admin']);

it('maps every field of a successful response, padding the integer postal code', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response(clientPayload($number))]);

    $company = app(AresClient::class)->lookup($number);

    expect($company->companyNumber)->toBe($number)
        ->and($company->name)->toBe('Example Registry Ltd.')
        ->and($company->taxNumber)->toBe('CZ'.$number)
        ->and($company->street)->toBe('Example Street 12/3')
        ->and($company->city)->toBe('Example Town')
        ->and($company->postalCode)->toBe('01100')
        ->and($company->country)->toBe('CZ')
        ->and($company->formState())->toHaveKeys(['name', 'company_number', 'tax_number', 'street', 'city', 'postal_code', 'country']);
});

it('leaves the tax number out of the form state when ARES publishes none', function (): void {
    $number = FictionalCompanyId::valid();
    $payload = clientPayload($number);
    unset($payload['dic']);
    Http::fake(['*' => Http::response($payload)]);

    $company = app(AresClient::class)->lookup($number);

    expect($company->taxNumber)->toBeNull()
        ->and($company->formState())->not->toHaveKey('tax_number');
});

it('falls back from the street to the municipality part, the municipality and the text address', function (array $seat, string $street): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response(clientPayload($number, $seat))]);

    expect(app(AresClient::class)->lookup($number)->street)->toBe($street);
})->with([
    'municipality part' => [['nazevCastiObce' => 'Example Quarter', 'nazevObce' => 'Example Town', 'cisloDomovni' => 7], 'Example Quarter 7'],
    'municipality' => [['nazevObce' => 'Example Town', 'cisloDomovni' => 7, 'cisloOrientacni' => 2], 'Example Town 7/2'],
    'text address' => [['textovaAdresa' => 'Example Village 5, 12345 Example Village'], 'Example Village 5, 12345 Example Village'],
]);

it('asks once and maps 404 to not found', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response(['kod' => 'NENALEZENO'], 404)]);

    expect(lookupFailure($number))->toBe(AresFailure::NotFound);

    Http::assertSentCount(1);
});

it('asks once and maps 400 to an invalid id', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response(['kod' => 'CHYBA_VSTUPU'], 400)]);

    expect(lookupFailure($number))->toBe(AresFailure::InvalidId);

    Http::assertSentCount(1);
});

it('retries server errors and succeeds on the third request', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::sequence()
        ->push('', 500)
        ->push('', 500)
        ->push(clientPayload($number), 200)]);

    expect(app(AresClient::class)->lookup($number)->name)->toBe('Example Registry Ltd.');

    Http::assertSentCount(3);
});

it('gives up with unavailable after three server errors', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response('', 500)]);

    expect(lookupFailure($number))->toBe(AresFailure::Unavailable);

    Http::assertSentCount(3);
});

it('maps a connection error to unavailable', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(static function (): never {
        throw new ConnectionException('Connection refused');
    });

    expect(lookupFailure($number))->toBe(AresFailure::Unavailable);
});

it('maps 429 to rate limited without a retry', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response('', 429)]);

    expect(lookupFailure($number))->toBe(AresFailure::RateLimited);

    Http::assertSentCount(1);
});

it('maps a body that is not JSON or lacks the company number to malformed', function (mixed $body): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response($body, 200)]);

    expect(lookupFailure($number))->toBe(AresFailure::Malformed);
})->with([
    'html maintenance page' => '<html>Maintenance</html>',
    'no company number' => [['obchodniJmeno' => 'Example Registry Ltd.']],
    'no name' => [['ico' => '12345678']],
]);

it('serves a successful lookup from the cache', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response(clientPayload($number))]);

    app(AresClient::class)->lookup($number);
    $again = app(AresClient::class)->lookup($number);

    expect($again->name)->toBe('Example Registry Ltd.');
    Http::assertSentCount(1);
});

it('does not cache a failure', function (): void {
    $number = FictionalCompanyId::valid();
    Http::fake(['*' => Http::response('', 404)]);

    lookupFailure($number);
    lookupFailure($number);

    Http::assertSentCount(2);
});

it('limits one user to ten lookups a minute and sends no request for the eleventh', function (): void {
    Http::fake(static fn ($request) => Http::response(clientPayload(basename($request->url()))));

    foreach (range(1, 10) as $ignored) {
        app(AresClient::class)->lookup(FictionalCompanyId::valid());
    }

    expect(lookupFailure(FictionalCompanyId::valid()))->toBe(AresFailure::RateLimited);

    Http::assertSentCount(10);
});

it('counts the limit per user', function (): void {
    Http::fake(static fn ($request) => Http::response(clientPayload(basename($request->url()))));

    $first = Canary::admin();
    $this->actingAs($first);

    foreach (range(1, 10) as $ignored) {
        app(AresClient::class)->lookup(FictionalCompanyId::valid());
    }

    $second = Canary::admin();
    $this->actingAs($second);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(app(AresClient::class)->lookup(FictionalCompanyId::valid())->name)->toBe('Example Registry Ltd.');
});
