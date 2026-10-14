<?php

declare(strict_types=1);

namespace App\Domain\Api\Models;

use App\Domain\Shared\Auth\NotPartnerScoped;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;

/**
 * The claim of one Idempotency-Key of one API token, and the 2xx answer stored for its replay.
 *
 * Written only through IdempotencyStore. The row is infrastructure keyed by the token, never data a
 * Partner could reach.
 *
 * @property string $id
 * @property string $token_id
 * @property string $key
 * @property string $request_hash
 * @property CarbonImmutable $locked_at
 * @property int|null $response_status
 * @property string|null $response_body
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $expires_at
 */
#[NotPartnerScoped(reason: 'API infrastructure keyed by token, never a Partner surface')]
final class IdempotencyKey extends KokpitModel
{
    protected $table = 'api_idempotency_keys';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'response_status' => 'integer',
        ];
    }
}
