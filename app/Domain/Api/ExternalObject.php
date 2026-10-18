<?php

declare(strict_types=1);

namespace App\Domain\Api;

use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

/**
 * The one rule of every free-form `external` object an import tool keeps next to a row: time entries (D-15) and
 * transactions.
 *
 * The messages keep their D-15 keys under `kokpit.time.errors`: their wording names only the field ("Pole
 * external ..."), so one set serves both tables.
 */
final class ExternalObject
{
    /**
     * How many objects or lists may be nested in `external`, the object itself being the first level.
     */
    public const int MAX_DEPTH = 8;

    /**
     * The free-form `external` object of an import helper: null stays null, a JSON object within the depth and
     * size limits is returned as it is.
     *
     * Only a `stdClass` is an object: a PHP array cannot tell `{}` from `[]`, so a caller that decodes a request
     * body must decode it as objects (`RawJsonBody::of()`) and hand the member over untouched. An array, a
     * string, a number or a bool is refused. The size is the byte length of the compact JSON, so multi-byte text
     * counts in bytes.
     *
     * @throws ValidationException a field error on `external`: `external_not_object`, `external_too_deep`
     *                             (more than MAX_DEPTH levels) or `external_too_large`
     *                             (more than `kokpit.api.external_max_bytes` bytes)
     */
    public static function parse(mixed $value): ?stdClass
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof stdClass) {
            throw self::error('external_not_object');
        }

        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, self::MAX_DEPTH);
        } catch (JsonException $e) {
            // Beyond the depth is its own answer; anything else json_encode refuses (a float that is not
            // finite) is not a JSON value an object may hold.
            throw self::error($e->getCode() === JSON_ERROR_DEPTH ? 'external_too_deep' : 'external_not_object');
        }

        $max = (int) config('kokpit.api.external_max_bytes');

        if (strlen($json) > $max) {
            throw self::error('external_too_large', ['max' => $max]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function error(string $message, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['external' => __('kokpit.time.errors.'.$message, $replace)]);
    }
}
