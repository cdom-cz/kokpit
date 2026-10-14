<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Facades\FilamentTimezone;

/**
 * How long a new API token stays valid (D-02): never expires, or 30, 90 or 365 days.
 *
 * A limited token ends at the end of the chosen day in the display time zone.
 */
enum ApiTokenExpiry: string implements HasLabel
{
    case None = 'none';
    case Days30 = 'days_30';
    case Days90 = 'days_90';
    case Days365 = 'days_365';

    public function getLabel(): string
    {
        return __('kokpit.api_tokens.expiry.'.$this->value);
    }

    /**
     * The last whole second of the chosen day in Europe/Prague, as a UTC instant, or null for no limit.
     *
     * The column is timestamptz(0), which rounds a fraction up, so 23:59:59.999999 would
     * land on the next day; the instant is therefore cut to the whole second first.
     */
    public function expiresAt(CarbonInterface $now): ?CarbonImmutable
    {
        $days = match ($this) {
            self::None => null,
            self::Days30 => 30,
            self::Days90 => 90,
            self::Days365 => 365,
        };

        if ($days === null) {
            return null;
        }

        return CarbonImmutable::instance($now)
            ->setTimezone(FilamentTimezone::get())
            ->addDays($days)
            ->endOfDay()
            ->startOfSecond()
            ->utc();
    }
}
