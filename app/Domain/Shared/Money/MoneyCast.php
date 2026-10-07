<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Persists a {@see Money} in two columns: `{key}_minor` (bigint) and
 * `{key}_currency` (char(3), upper case by a CHECK constraint).
 *
 * Both columns null means "no amount"; exactly one null is corrupt data and
 * throws instead of guessing.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $minor = $attributes["{$key}_minor"] ?? null;
        $currency = $attributes["{$key}_currency"] ?? null;

        if ($minor === null && $currency === null) {
            return null;
        }

        if ($minor === null || $currency === null) {
            throw new InvalidArgumentException("The money attribute {$key} has only one of its two columns set.");
        }

        if (! is_int($minor) && ! (is_string($minor) && preg_match('/^-?\d+$/', $minor) === 1)) {
            throw new InvalidArgumentException("The {$key}_minor column does not hold an integer.");
        }

        if (! is_string($currency)) {
            throw new InvalidArgumentException("The {$key}_currency column does not hold a string.");
        }

        return Money::ofMinor((int) $minor, $currency);
    }

    /**
     * Anything but a Money or null is rejected at runtime, which is why the
     * settable type of the cast is declared as mixed.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return ["{$key}_minor" => null, "{$key}_currency" => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The money attribute {$key} accepts a Money instance or null.");
        }

        return ["{$key}_minor" => $value->minor, "{$key}_currency" => $value->currency];
    }
}
