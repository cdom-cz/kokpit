<?php

declare(strict_types=1);

namespace App\Domain\Api\Idempotency;

use App\Domain\Api\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

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
     *
     * An expired row counts as absent and is deleted. A claim without a response that is older than the
     * stale-lock setting belongs to a crashed request and is taken over by one atomic UPDATE, so of two
     * concurrent retries only one wins it.
     */
    public function claim(string $tokenId, string $key, string $requestHash): IdempotencyClaim
    {
        // Twice is enough: the second round only follows a row that expired or vanished since the insert.
        for ($round = 0; $round < 2; $round++) {
            $now = CarbonImmutable::now();

            $row = $this->insert($tokenId, $key, $requestHash, $now);

            if ($row !== null) {
                return new IdempotencyClaim(IdempotencyOutcome::Claimed, $row);
            }

            $existing = IdempotencyKey::query()->where('token_id', $tokenId)->where('key', $key)->first();

            if ($existing === null) {
                continue;
            }

            if ($existing->expires_at->lessThanOrEqualTo($now)) {
                // Only the row that was read: a fresh claim of the same key must survive.
                IdempotencyKey::query()->whereKey($existing->id)->where('expires_at', '<=', self::stamp($now))->delete();

                continue;
            }

            if (! hash_equals($existing->request_hash, $requestHash)) {
                return new IdempotencyClaim(IdempotencyOutcome::Mismatch);
            }

            if ($existing->completed_at !== null) {
                return new IdempotencyClaim(IdempotencyOutcome::Replay, $existing);
            }

            return $this->takeOver($existing, $now);
        }

        return new IdempotencyClaim(IdempotencyOutcome::InProgress);
    }

    /**
     * Stores the 2xx answer of the request that owns the claim, for replay.
     *
     * Ownership is the claim time: a request that was overtaken after it went stale finds the time moved
     * and changes nothing.
     */
    public function complete(IdempotencyKey $row, int $status, string $body): void
    {
        $now = CarbonImmutable::now();

        $this->owned($row)->update([
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
        $this->owned($row)->delete();
    }

    /**
     * Takes the claim of a crashed request when it is older than the stale-lock setting, otherwise the key is busy.
     */
    private function takeOver(IdempotencyKey $existing, CarbonImmutable $now): IdempotencyClaim
    {
        $stale = $now->subSeconds(max(1, (int) config('kokpit.api.idempotency_stale_lock_seconds')));

        if ($existing->locked_at->greaterThanOrEqualTo($stale)) {
            return new IdempotencyClaim(IdempotencyOutcome::InProgress);
        }

        $taken = IdempotencyKey::query()
            ->whereKey($existing->id)
            ->whereNull('completed_at')
            ->where('locked_at', '<', self::stamp($stale))
            ->update([
                'locked_at' => self::stamp($now),
                'expires_at' => self::stamp($now->addHours($this->ttlHours())),
                'updated_at' => self::stamp($now),
            ]);

        $row = $taken === 1 ? IdempotencyKey::query()->find($existing->id) : null;

        return $row === null
            ? new IdempotencyClaim(IdempotencyOutcome::InProgress)
            : new IdempotencyClaim(IdempotencyOutcome::Claimed, $row);
    }

    /**
     * The row of the claim, only while nobody completed it and nobody took it over.
     *
     * @return Builder<IdempotencyKey>
     */
    private function owned(IdempotencyKey $row): Builder
    {
        return IdempotencyKey::query()
            ->whereKey($row->id)
            ->whereNull('completed_at')
            ->where('locked_at', self::stamp($row->locked_at));
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('kokpit.api.idempotency_ttl_hours'));
    }

    /**
     * One atomic insert of the claim; the row when this call won the key, null when the key exists.
     */
    private function insert(string $tokenId, string $key, string $requestHash, CarbonImmutable $now): ?IdempotencyKey
    {
        $id = (new IdempotencyKey)->newUniqueId();

        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'id' => $id,
            'token_id' => $tokenId,
            'key' => $key,
            'request_hash' => $requestHash,
            'locked_at' => self::stamp($now),
            'expires_at' => self::stamp($now->addHours($this->ttlHours())),
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
