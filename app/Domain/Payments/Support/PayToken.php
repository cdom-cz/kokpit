<?php

declare(strict_types=1);

namespace App\Domain\Payments\Support;

use Illuminate\Support\Str;

/**
 * The bearer token of the stable pay URL (D-01): 32 random letters and digits from the CSPRNG, about 190 bits. It is
 * generated when the document first offers card payment (at issue, or later on an open issued document) and set once: it never
 * changes, not even when the card switch is turned off and on again (Phase 14, D-10, OA-1). It is never logged and never derived from
 * anything else.
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
