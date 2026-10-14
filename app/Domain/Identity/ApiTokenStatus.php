<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where a token stands, computed from its expiry (UI-SPEC U-9): active, expiring within
 * seven days, or expired. A token without an expiry is always active. The threshold is
 * fixed, and the status changes no behaviour: it is only a hint on the token list.
 */
enum ApiTokenStatus: string implements HasColor, HasIcon, HasLabel
{
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';

    /** The days before the expiry from which a token reads "expires soon". */
    private const int SOON_DAYS = 7;

    public static function for(?CarbonInterface $expiresAt, CarbonInterface $now): self
    {
        if ($expiresAt === null) {
            return self::Active;
        }

        if ($expiresAt->lessThanOrEqualTo($now)) {
            return self::Expired;
        }

        return $expiresAt->lessThanOrEqualTo($now->copy()->addDays(self::SOON_DAYS)) ? self::ExpiringSoon : self::Active;
    }

    public function getLabel(): string
    {
        return __('kokpit.api_tokens.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::ExpiringSoon => 'warning',
            self::Expired => 'gray',
        };
    }

    public function getIcon(): ?Heroicon
    {
        return match ($this) {
            self::Active => null,
            self::ExpiringSoon => Heroicon::OutlinedExclamationTriangle,
            self::Expired => Heroicon::OutlinedClock,
        };
    }
}
