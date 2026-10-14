<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Error answers of the REST API that Laravel has no shape for.
 *
 * A 422 keeps Laravel's `{message, errors}` shape with the field keys of the Actions. A conflict carries
 * a stable machine `code` next to the Czech `message`, so an API client can branch without parsing text.
 */
final class ApiError
{
    /**
     * 409 `{message, code}`.
     */
    public static function conflict(string $code, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], 409);
    }
}
