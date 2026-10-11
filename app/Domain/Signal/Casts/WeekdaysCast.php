<?php

declare(strict_types=1);

namespace App\Domain\Signal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a PostgreSQL `smallint[]` column to a list of weekday indexes (0 = Monday ... 6 = Sunday).
 *
 * The database holds the array literal `{0,2,4}`; the model sees `[0, 2, 4]`. Writing normalizes the
 * list: values are cast to integers, duplicates dropped and the list sorted, so `[4, "0", 0]` is stored
 * as `{0,4}`. An index outside 0..6 and an empty list are refused here, before the database CHECK
 * (`cardinality(weekdays) BETWEEN 1 AND 7`) would refuse them. Reading an empty array is still possible.
 *
 * @implements CastsAttributes<list<int>, iterable<int|string>>
 */
final class WeekdaysCast implements CastsAttributes
{
    /**
     * @return list<int>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return is_string($value) ? self::parse($value) : [];
    }

    /**
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_iterable($value)) {
            throw new InvalidArgumentException('Weekdays must be a list of indexes.');
        }

        return [$key => self::format([...$value])];
    }

    /**
     * Reads the array literal `{0,2,4}` of PostgreSQL.
     *
     * @return list<int>
     */
    public static function parse(string $literal): array
    {
        $inner = trim($literal, "{} \t\n");

        if ($inner === '') {
            return [];
        }

        return array_map(intval(...), explode(',', $inner));
    }

    /**
     * Writes the normalized array literal `{0,2,4}`.
     *
     * @param  array<int|string>  $weekdays
     *
     * @throws InvalidArgumentException for an empty list or an index outside 0..6
     */
    public static function format(array $weekdays): string
    {
        if ($weekdays === []) {
            throw new InvalidArgumentException('A template needs at least one weekday.');
        }

        $indexes = [];

        foreach ($weekdays as $weekday) {
            if (filter_var($weekday, FILTER_VALIDATE_INT) === false || (int) $weekday < 0 || (int) $weekday > 6) {
                throw new InvalidArgumentException('A weekday index is 0 (Monday) to 6 (Sunday).');
            }

            $indexes[(int) $weekday] = (int) $weekday;
        }

        ksort($indexes);

        return '{'.implode(',', $indexes).'}';
    }
}
