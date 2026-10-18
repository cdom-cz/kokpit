<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The one exception that leaves the Stripe gateway (D-09, D-18).
 *
 * A provider answer can name the payer, carry a request id or echo a parameter, and a failed-job alert prints
 * the first line of a message into the bell, so the message is fixed text built from the failure class only
 * (T-11-01). The SDK exception stays as the previous exception, which only the failed_jobs record and the log
 * hold. Only a lost connection and a rate limit are worth a retry: Stripe replays a stored 5xx answer for the
 * same idempotency key, so retrying an api_error with that key would only repeat the failure (Pitfall 7).
 */
final class StripeGatewayFailure extends RuntimeException
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string AUTHENTICATION = 'authentication';

    public const string INVALID_REQUEST = 'invalid_request';

    public const string RATE_LIMITED = 'rate_limited';

    public const string CONNECTION = 'connection';

    public const string API_ERROR = 'api_error';

    public const string IDEMPOTENCY = 'idempotency';

    public const string UNSUPPORTED_CURRENCY = 'unsupported_currency';

    private function __construct(public readonly string $failureClass, ?Throwable $previous = null)
    {
        parent::__construct('Stripe request failed: '.$failureClass, 0, $previous);
    }

    public static function notConfigured(?Throwable $previous = null): self
    {
        return new self(self::NOT_CONFIGURED, $previous);
    }

    public static function authentication(?Throwable $previous = null): self
    {
        return new self(self::AUTHENTICATION, $previous);
    }

    public static function invalidRequest(?Throwable $previous = null): self
    {
        return new self(self::INVALID_REQUEST, $previous);
    }

    public static function rateLimited(?Throwable $previous = null): self
    {
        return new self(self::RATE_LIMITED, $previous);
    }

    public static function connection(?Throwable $previous = null): self
    {
        return new self(self::CONNECTION, $previous);
    }

    public static function apiError(?Throwable $previous = null): self
    {
        return new self(self::API_ERROR, $previous);
    }

    public static function idempotency(?Throwable $previous = null): self
    {
        return new self(self::IDEMPOTENCY, $previous);
    }

    public static function unsupportedCurrency(?Throwable $previous = null): self
    {
        return new self(self::UNSUPPORTED_CURRENCY, $previous);
    }

    /**
     * The failure of a named class, for code that carries the class as data (the fake gateway of the tests,
     * a stored failure class of a link row).
     *
     * @throws InvalidArgumentException for a class that does not exist
     */
    public static function forClass(string $failureClass, ?Throwable $previous = null): self
    {
        return match ($failureClass) {
            self::NOT_CONFIGURED => self::notConfigured($previous),
            self::AUTHENTICATION => self::authentication($previous),
            self::INVALID_REQUEST => self::invalidRequest($previous),
            self::RATE_LIMITED => self::rateLimited($previous),
            self::CONNECTION => self::connection($previous),
            self::API_ERROR => self::apiError($previous),
            self::IDEMPOTENCY => self::idempotency($previous),
            self::UNSUPPORTED_CURRENCY => self::unsupportedCurrency($previous),
            default => throw new InvalidArgumentException('Unknown Stripe failure class.'),
        };
    }

    /**
     * Whether the same request may be sent again later: a lost connection or a rate limit. A 5xx, a
     * rejected request, a missing key and an idempotency clash are not retried (R11-01-3).
     */
    public function isRetryable(): bool
    {
        return $this->failureClass === self::CONNECTION || $this->failureClass === self::RATE_LIMITED;
    }
}
