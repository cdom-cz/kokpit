<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * What the public pay page does for a token (D-02, UI-SPEC Surface A): sends the visitor to the active Stripe link or
 * shows one of four login-free cards. Invalid covers every cause of a bad token, so the page tells nothing apart.
 */
enum PayPageOutcome: string
{
    case Redirect = 'redirect';
    case Preparing = 'preparing';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Invalid = 'invalid';
}
