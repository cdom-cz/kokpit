<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a Partner invitation stands (D-01, D-02).
 *
 * Never stored: ClientInvitation::state() derives it from accepted_at,
 * revoked_at, expires_at and the current time, so it cannot drift.
 */
enum InvitationState: string implements HasLabel
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return __('enums.invitation_state.'.$this->value);
    }
}
