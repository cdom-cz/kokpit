<?php

declare(strict_types=1);

namespace App\Domain\Clients\Ares;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The ARES registry lookup by company number (CL-04): one synchronous GET on the
 * public REST endpoint, no key, no account.
 *
 * The id is validated (8 digits, checksum) before any URL is built, so nothing
 * unvalidated reaches the request path (T-04-28). Only connection errors and 5xx
 * responses are retried; a 404 or 400 is asked exactly once. Successful lookups
 * are cached for an hour by company number, failures never, and a per-user limiter
 * keeps a script from hammering the service.
 */
final class AresClient
{
    private const CACHE_SECONDS = 3600;

    private const LIMIT_PER_MINUTE = 10;

    /**
     * @throws AresLookupFailed
     */
    public function lookup(string $companyNumber): AresCompany
    {
        $companyNumber = trim($companyNumber);

        if (! CompanyId::isValid($companyNumber)) {
            throw new AresLookupFailed(AresFailure::InvalidId);
        }

        $this->hitLimiter();

        /** @var array{companyNumber: string, name: string, taxNumber: ?string, street: ?string, city: ?string, postalCode: ?string, country: string} $data */
        $data = Cache::remember(
            'ares:'.$companyNumber,
            self::CACHE_SECONDS,
            fn (): array => $this->fetch($companyNumber)->toArray(),
        );

        return AresCompany::fromArray($data);
    }

    private function hitLimiter(): void
    {
        $key = 'ares:'.(auth()->id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, self::LIMIT_PER_MINUTE)) {
            throw new AresLookupFailed(AresFailure::RateLimited);
        }

        RateLimiter::hit($key, 60);
    }

    private function fetch(string $companyNumber): AresCompany
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                // Without `when`, Laravel retries every failed response, a 404 included.
                ->retry(
                    3,
                    200,
                    when: static fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->get(rtrim((string) config('services.ares.base_url'), '/').'/'.$companyNumber);
        } catch (ConnectionException) {
            throw new AresLookupFailed(AresFailure::Unavailable);
        }

        if ($response->successful()) {
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new AresLookupFailed(AresFailure::Malformed);
            }

            return AresCompany::fromResponse($payload);
        }

        throw new AresLookupFailed(match ($response->status()) {
            404 => AresFailure::NotFound,
            400 => AresFailure::InvalidId,
            429 => AresFailure::RateLimited,
            default => AresFailure::Unavailable,
        });
    }
}
