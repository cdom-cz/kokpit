<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Filament\Support\Contracts\HasLabel;

/**
 * The abilities an API token can carry.
 *
 * The backed value is the ability string stored on the token and named in the
 * `abilities:` route middleware, so a route and a token can never drift apart.
 */
enum ApiAbility: string implements HasLabel
{
    case TimeRead = 'time:read';
    case TimeWrite = 'time:write';

    /**
     * The Czech label shown on the token screen, one place for the list badges and the create options.
     */
    public function getLabel(): string
    {
        return __('kokpit.api_tokens.abilities.'.str_replace(':', '_', $this->value));
    }

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
