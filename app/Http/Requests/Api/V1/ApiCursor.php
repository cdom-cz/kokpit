<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use LogicException;

/**
 * The validation rule of the `cursor` query parameter of every list endpoint.
 *
 * Laravel's `Cursor::fromEncoded()` checks neither the keys nor the values of a decoded cursor: any base64url
 * JSON object that has `_pointsToNextItems` is accepted. A cursor with a missing key then throws an
 * `UnexpectedValueException`, and a wrong-typed value reaches a uuid, timestamptz or text column as a bound
 * parameter and fails in PostgreSQL (22P02, 22007). Both were a 500 for a client that edited or mixed up a cursor.
 *
 * A valid cursor is one the API could have issued for the list and the sort in question: it decodes like Laravel
 * decodes it, to an array whose keys are exactly the order columns of the list (the sort column and the table id)
 * plus a boolean `_pointsToNextItems`, and each value has the form the paginator writes for its column. A cursor
 * that Laravel would silently read as "first page" (not decodable, not an object, no direction flag) is refused
 * too, because the API never issues one.
 */
final class ApiCursor
{
    /**
     * A uuid string, the form of an id column.
     */
    public const string UUID = 'uuid';

    /**
     * A timestamp in the form the paginator writes, `Y-m-d H:i:s` in UTC.
     */
    public const string INSTANT = 'instant';

    /**
     * A string without a NUL byte, the form of a name or title column.
     */
    public const string TEXT = 'text';

    /**
     * A calendar day of a `date` column, written as `Y-m-d` or as the zero-time form `Y-m-d 00:00:00` that the
     * paginator writes for a date cast.
     */
    public const string DATE = 'date';

    /**
     * A JSON integer, the form of an integer column such as an amount in minor units.
     */
    public const string INTEGER = 'integer';

    /**
     * The format the paginator writes a timestamp column in (the application timezone is UTC).
     */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s';

    /**
     * The format of a calendar day.
     */
    private const string DAY_FORMAT = 'Y-m-d';

    /**
     * The key of the direction flag Laravel adds to every cursor.
     */
    private const string DIRECTION_KEY = '_pointsToNextItems';

    /**
     * A validation rule that accepts only a cursor with exactly the given order columns.
     *
     * @param  array<string, 'uuid'|'instant'|'text'|'date'|'integer'>  $columns  order column as it appears in the cursor => kind of its value
     */
    public static function rule(array $columns): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($columns): void {
            if (! is_string($value) || ! self::accepts($value, $columns)) {
                $fail(__('validation.regex', ['attribute' => $attribute]));
            }
        };
    }

    /**
     * The kind of cursor value a sort column is written as.
     *
     * @return 'uuid'|'instant'|'text'|'date'|'integer'
     */
    public static function kindOf(string $sortColumn): string
    {
        return match ($sortColumn) {
            'started_at', 'created_at' => self::INSTANT,
            'name', 'title' => self::TEXT,
            'transaction_date' => self::DATE,
            'czk_minor' => self::INTEGER,
            default => throw new LogicException('No cursor kind is declared for the sort column "'.$sortColumn.'".'),
        };
    }

    /**
     * @param  array<string, 'uuid'|'instant'|'text'|'date'|'integer'>  $columns
     */
    private static function accepts(string $encoded, array $columns): bool
    {
        $parameters = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $encoded)), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($parameters)) {
            return false;
        }

        if (! is_bool($parameters[self::DIRECTION_KEY] ?? null)) {
            return false;
        }

        unset($parameters[self::DIRECTION_KEY]);

        $keys = array_map(strval(...), array_keys($parameters));
        $expected = array_keys($columns);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            return false;
        }

        foreach ($columns as $column => $kind) {
            if (! self::hasKind($parameters[$column] ?? null, $kind)) {
                return false;
            }
        }

        return true;
    }

    private static function hasKind(mixed $value, string $kind): bool
    {
        // A JSON integer is the only form the paginator writes for an integer column; a numeric string would be
        // bound as text, so it is refused.
        if ($kind === self::INTEGER) {
            return is_int($value);
        }

        if (! is_string($value)) {
            return false;
        }

        return match ($kind) {
            self::UUID => Str::isUuid($value),
            self::INSTANT => self::isInstant($value),
            self::DATE => self::isDay($value),
            self::TEXT => ! str_contains($value, "\0"),
            default => false,
        };
    }

    /**
     * A real calendar date and time written exactly as `Y-m-d H:i:s`, never in year 0000.
     */
    private static function isInstant(string $value): bool
    {
        $instant = DateTimeImmutable::createFromFormat('!'.self::INSTANT_FORMAT, $value, new DateTimeZone('UTC'));

        return $instant !== false
            && DateTimeImmutable::getLastErrors() === false
            && $instant->format(self::INSTANT_FORMAT) === $value
            && ! str_starts_with($value, '0000');
    }

    /**
     * A real calendar day written exactly as `Y-m-d` or as `Y-m-d 00:00:00`, never in year 0000.
     */
    private static function isDay(string $value): bool
    {
        $day = str_ends_with($value, ' 00:00:00') ? substr($value, 0, -strlen(' 00:00:00')) : $value;
        $parsed = DateTimeImmutable::createFromFormat('!'.self::DAY_FORMAT, $day, new DateTimeZone('UTC'));

        return $parsed !== false
            && DateTimeImmutable::getLastErrors() === false
            && $parsed->format(self::DAY_FORMAT) === $day
            && ! str_starts_with($day, '0000');
    }
}
