<?php

declare(strict_types=1);

namespace App\Domain\Clients\Ares;

use RuntimeException;

/**
 * Thrown by AresClient when a lookup cannot return a company. The form turns it
 * into a field error and leaves every input as it was (D-08).
 */
final class AresLookupFailed extends RuntimeException
{
    public function __construct(public readonly AresFailure $failure)
    {
        parent::__construct('ARES lookup failed: '.$failure->value);
    }

    public function userMessage(): string
    {
        return $this->failure->userMessage();
    }
}
