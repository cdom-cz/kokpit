<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\Idempotency\IdempotencyOutcome;
use App\Domain\Api\Idempotency\IdempotencyStore;
use App\Http\Responses\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a retried create safe with an optional `Idempotency-Key` header (D-06 to D-08, OD-3).
 *
 * Without the header the request runs as before. With it, the key is claimed for the token before the
 * controller runs: the same request again gets the stored 2xx answer (marked with `Idempotent-Replayed`),
 * the same key with another body is refused, and a retry while the first request still runs is a 409.
 * Only a 2xx answer is stored; a failed request releases the key, so the corrected request with the same
 * key creates the entry. Route it only on create routes, after authentication, ability and rate limit.
 */
final class EnsureIdempotency
{
    /** Printable ASCII without a space, 1 to 255 characters. */
    private const string KEY_PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Idempotency-Key')) {
            return $next($request);
        }

        $key = $request->headers->get('Idempotency-Key');

        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return self::refuse('idempotency_key_invalid', __('api.errors.idempotency_key_invalid'));
        }

        // The key belongs to the token that sent it; an answer is never replayed to another token (D-08).
        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        if (! is_string($tokenId)) {
            return $next($request);
        }

        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
        $claim = $this->store->claim($tokenId, $key, $hash);

        switch ($claim->outcome) {
            case IdempotencyOutcome::Replay:
                return new Response((string) $claim->row?->response_body, (int) $claim->row?->response_status, [
                    'Content-Type' => 'application/json',
                    'Idempotent-Replayed' => 'true',
                ]);
            case IdempotencyOutcome::Mismatch:
                return self::refuse('idempotency_key_reused', __('api.errors.idempotency_key_reused'));
            case IdempotencyOutcome::InProgress:
                return ApiError::conflict('idempotency_in_progress', __('api.errors.idempotency_in_progress'));
            case IdempotencyOutcome::Claimed:
                break;
        }

        $row = $claim->row;

        if ($row === null) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->store->release($row);

            throw $e;
        }

        if ($response->isSuccessful()) {
            $this->store->complete($row, $response->getStatusCode(), (string) $response->getContent());
        } else {
            $this->store->release($row);
        }

        return $response;
    }

    /**
     * 422 `{message, code}` for a key the API cannot honour.
     */
    private static function refuse(string $code, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], 422);
    }
}
