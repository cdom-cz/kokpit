<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * The abilities an API token can carry.
 *
 * The backed value is the ability string stored on the token and named in the
 * `abilities:` route middleware, so a route and a token can never drift apart.
 */
enum ApiAbility: string
{
    case TimeRead = 'time:read';
    case TimeWrite = 'time:write';

    /**
     * Every ability string, in declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }
}
