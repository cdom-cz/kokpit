<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a Stripe payment link of a document stands (PA-01, PA-04; D-07, D-08).
 *
 * Pending is a row whose Stripe link is not yet created, active one that offers the payment, failed one whose
 * creation did not succeed and deactivated one that no longer offers it. Failed and deactivated are final; a new
 * link is a new row. "Vypnuto" (switched off) is not a state: the link section derives it from the stored card switch.
 */
enum PaymentLinkState: string implements HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Deactivated = 'deactivated';

    /**
     * Pending and active are the live states: at most one live link exists per document.
     */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }

    public function getLabel(): string
    {
        return __('enums.payment_link_state.'.$this->value);
    }
}
