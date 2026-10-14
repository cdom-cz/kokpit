<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Api\Idempotency\IdempotencyClaim;
use App\Domain\Api\Idempotency\IdempotencyOutcome;
use App\Domain\Api\Idempotency\IdempotencyStore;
use App\Domain\Api\Models\IdempotencyKey;
use RuntimeException;

/**
 * Test-only mutation for the idempotency concurrency harness; never used by application code.
 *
 * Identical to IdempotencyStore except that claim() claims nothing: it answers Claimed with an unsaved row
 * every time, and complete() and release() do nothing. Two parallel requests with one key are therefore no
 * longer told apart and each runs the create.
 *
 * Without a rendezvous the double just skips the claim, and the duplicates would show at natural timing, which
 * is not reliable. With a rendezvous directory and a party count it forces the overlap: claim() writes the file
 * `<dir>/<pid>` and polls until the directory holds `<parties>` files, so every party is past the claim before
 * any of them creates an entry. A party that never arrives makes the others fail with `rendezvous timeout`
 * after 15 seconds, which fails the run loudly instead of passing by luck.
 */
final class UnclaimedIdempotencyStore extends IdempotencyStore
{
    private const int TIMEOUT_SECONDS = 15;

    private const int POLL_MICROSECONDS = 5000;

    public function __construct(private readonly ?string $rendezvousDir = null, private readonly int $parties = 0) {}

    #[\Override]
    public function claim(string $tokenId, string $key, string $requestHash): IdempotencyClaim
    {
        if ($this->rendezvousDir !== null) {
            $this->rendezvous($this->rendezvousDir);
        }

        $row = new IdempotencyKey;
        $row->forceFill(['token_id' => $tokenId, 'key' => $key, 'request_hash' => $requestHash]);

        return new IdempotencyClaim(IdempotencyOutcome::Claimed, $row);
    }

    #[\Override]
    public function complete(IdempotencyKey $row, int $status, string $body): void {}

    #[\Override]
    public function release(IdempotencyKey $row): void {}

    private function rendezvous(string $dir): void
    {
        file_put_contents($dir.'/'.getmypid(), '');

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (count(glob($dir.'/*') ?: []) < $this->parties) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('rendezvous timeout');
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }
}
