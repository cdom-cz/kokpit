<?php

declare(strict_types=1);

namespace App\Domain\Clients\Ares;

/**
 * Why an ARES lookup produced no company (CL-04, D-08). Every case has a Czech
 * message the client form shows next to the company number.
 */
enum AresFailure: string
{
    case InvalidId = 'invalid_id';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case Malformed = 'malformed';

    public function userMessage(): string
    {
        return __('kokpit.ares.errors.'.$this->value);
    }
}
