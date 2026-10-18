<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use Illuminate\Support\Str;

/**
 * The bearer token of the stable pay URL (D-01): 32 random letters and digits from the CSPRNG, about 190 bits. It is
 * generated once at issue and frozen with the document; it is never logged and never derived from anything else.
 */
final readonly class PayToken
{
    public const int LENGTH = 32;

    private const string PATTERN = '/^[A-Za-z0-9]{32}$/D';

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    /**
     * Whether the value has the exact shape of a token. Says nothing about whether it exists.
     */
    public static function isWellFormed(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
