<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRates\Cnb;

/**
 * Why a CNB download produced no list (EX-01). Every case has a Czech label the console, the manual download
 * action and the alert show; none of them ever carries response text.
 */
enum CnbFailure: string
{
    case Unavailable = 'unavailable';
    case Rejected = 'rejected';
    case ServerError = 'server_error';
    case Malformed = 'malformed';

    public function label(): string
    {
        return __('kokpit.rates.failure.'.$this->value);
    }
}
