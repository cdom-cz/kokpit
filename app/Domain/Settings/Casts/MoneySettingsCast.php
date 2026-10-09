<?php

declare(strict_types=1);

namespace App\Domain\Settings\Casts;

use App\Domain\Shared\Money\Money;
use InvalidArgumentException;
use Spatie\LaravelSettings\SettingsCasts\SettingsCast;

/**
 * Stores a Money value in the settings table as {minor, currency}, the shape of
 * Money::jsonSerialize(), and reads it back as a Money value object.
 */
final class MoneySettingsCast implements SettingsCast
{
    /**
     * @param  mixed  $payload  the decoded {minor, currency} payload
     *
     * @throws InvalidArgumentException when the stored payload is not a minor amount with a known currency
     */
    public function get($payload): Money
    {
        if (! is_array($payload) || ! is_int($payload['minor'] ?? null) || ! is_string($payload['currency'] ?? null)) {
            throw new InvalidArgumentException('A stored money setting must hold an integer minor amount and a currency code.');
        }

        return Money::ofMinor($payload['minor'], $payload['currency']);
    }

    /**
     * @param  Money  $payload
     * @return array{minor: int, currency: string}
     */
    public function set($payload): array
    {
        return $payload->jsonSerialize();
    }
}
