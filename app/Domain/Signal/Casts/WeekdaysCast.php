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
 * as `{0,4}`. An index outside 0..6 is refused here, before the database CHECK would refuse it.
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
     * @param  list<int|string>  $weekdays
     */
    public static function format(array $weekdays): string
    {
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
