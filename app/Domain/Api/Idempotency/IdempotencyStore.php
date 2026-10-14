<?php

declare(strict_types=1);

namespace App\Domain\Api\Idempotency;

use App\Domain\Api\Models\IdempotencyKey;
use Carbon\CarbonImmutable;

/**
 * Claims, completes and releases an Idempotency-Key (D-06 to D-08, OD-3).
 *
 * PostgreSQL is the source of truth: the claim is one atomic INSERT ... ON CONFLICT DO NOTHING on the
 * unique index (token_id, key), in its own statement and never inside a request transaction, so two
 * concurrent retries cannot both own the key. Not final on purpose, so the concurrency test can bind a
 * double without the claim lock, the way the timer lock has one.
 */
class IdempotencyStore
{
    /**
     * Claims the key of the token for the request with this hash, or says what it found instead.
     */
    public function claim(string $tokenId, string $key, string $requestHash): IdempotencyClaim
    {
        $now = CarbonImmutable::now();

        $row = $this->insert($tokenId, $key, $requestHash, $now);

        if ($row !== null) {
            return new IdempotencyClaim(IdempotencyOutcome::Claimed, $row);
        }

        $existing = IdempotencyKey::query()->where('token_id', $tokenId)->where('key', $key)->first();

        if ($existing === null) {
            return new IdempotencyClaim(IdempotencyOutcome::InProgress);
        }

        if (! hash_equals($existing->request_hash, $requestHash)) {
            return new IdempotencyClaim(IdempotencyOutcome::Mismatch);
        }

        if ($existing->completed_at !== null) {
            return new IdempotencyClaim(IdempotencyOutcome::Replay, $existing);
        }

        return new IdempotencyClaim(IdempotencyOutcome::InProgress);
    }

    /**
     * Stores the 2xx answer of the request that owns the claim, for replay.
     */
    public function complete(IdempotencyKey $row, int $status, string $body): void
    {
        $now = CarbonImmutable::now();

        IdempotencyKey::query()
            ->whereKey($row->id)
            ->whereNull('completed_at')
            ->update([
                'response_status' => $status,
                'response_body' => $body,
                'completed_at' => self::stamp($now),
                'updated_at' => self::stamp($now),
            ]);
    }

    /**
     * Gives the claim up after a failed request, so a corrected retry with the same key is a first request again.
     */
    public function release(IdempotencyKey $row): void
    {
        IdempotencyKey::query()
            ->whereKey($row->id)
            ->whereNull('completed_at')
            ->delete();
    }

    /**
     * One atomic insert of the claim; the row when this call won the key, null when the key exists.
     */
    private function insert(string $tokenId, string $key, string $requestHash, CarbonImmutable $now): ?IdempotencyKey
    {
        $id = (new IdempotencyKey)->newUniqueId();
        $ttlHours = max(1, (int) config('kokpit.api.idempotency_ttl_hours'));

        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'id' => $id,
            'token_id' => $tokenId,
            'key' => $key,
            'request_hash' => $requestHash,
            'locked_at' => self::stamp($now),
            'expires_at' => self::stamp($now->addHours($ttlHours)),
            'created_at' => self::stamp($now),
            'updated_at' => self::stamp($now),
        ]);

        return $inserted === 1 ? IdempotencyKey::query()->find($id) : null;
    }

    /**
     * A whole-second instant with its offset, so the column never reads it in the session time zone.
     */
    private static function stamp(CarbonImmutable $moment): string
    {
        return $moment->startOfSecond()->format('Y-m-d H:i:sP');
    }
}
