<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Http\Request;
use stdClass;

/**
 * The request body decoded as objects, once per request.
 *
 * Laravel decodes a JSON body to arrays, and a PHP array cannot tell the JSON object `{}` from the list `[]`.
 * The free-form `external` object (time entries, transactions) is therefore taken from this second decoding,
 * which keeps `{}` an object.
 */
final class RawJsonBody
{
    private const string ATTRIBUTE = 'kokpit.api.raw_body';

    /**
     * The JSON body of the request decoded as objects, once per request; null when the body is not a JSON
     * object (a form post, an empty or a malformed body).
     */
    public static function of(Request $request): ?stdClass
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $raw = json_decode($request->getContent(), false);

            $request->attributes->set(self::ATTRIBUTE, $raw instanceof stdClass ? $raw : null);
        }

        $raw = $request->attributes->get(self::ATTRIBUTE);

        return $raw instanceof stdClass ? $raw : null;
    }
}
