<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Cnb;

use RuntimeException;

/**
 * Thrown by CnbClient when a daily list cannot be returned as a valid, storable list.
 *
 * The message is the failure value only and the status is the HTTP status when there was a response: neither
 * the response body nor the text of a transport exception is ever carried, so nothing from the outside can
 * reach a console line, a log or an alert through this exception (T-08-13).
 */
final class CnbDownloadFailed extends RuntimeException
{
    public function __construct(
        public readonly CnbFailure $failure,
        public readonly ?int $status = null,
    ) {
        parent::__construct($failure->value);
    }
}
