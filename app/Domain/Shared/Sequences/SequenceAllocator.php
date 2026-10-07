<?php

declare(strict_types=1);

namespace App\Domain\Shared\Sequences;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * The one source of gap-free, duplicate-free numbers (D-11, D-12).
 *
 * A counter row per scope key is locked with SELECT ... FOR UPDATE inside the
 * caller's transaction. The lock is held until the caller commits or rolls
 * back, so a rolled-back caller gives the number back and two parallel callers
 * can never receive the same one. The allocator never resets a series: the
 * year is part of the key and a new key starts at 1.
 *
 * number_sequences.next_value is the NEXT number to hand out.
 */
class SequenceAllocator
{
    /** Same pattern as the CHECK constraint on number_sequences.scope_key. */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]*:[A-Za-z0-9._-]+$/D';

    private const string KIND_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    private const int KEY_MAX_LENGTH = 191;

    /**
     * Hands out the next number of the counter identified by $scopeKey.
     *
     * @throws LogicException when called outside a database transaction
     * @throws InvalidArgumentException when the key is malformed
     */
    public function next(string $scopeKey): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SequenceAllocator::next() must run inside the caller\'s transaction.');
        }

        self::assertValidKey($scopeKey);

        // First use: create the row at 1; a concurrent first use does nothing here.
        DB::insert(
            'INSERT INTO number_sequences (scope_key, next_value, created_at, updated_at)
             VALUES (?, 1, now(), now()) ON CONFLICT (scope_key) DO NOTHING',
            [$scopeKey],
        );

        $row = DB::selectOne('SELECT next_value FROM number_sequences WHERE scope_key = ? FOR UPDATE', [$scopeKey]);

        if ($row === null) {
            throw new RuntimeException('Counter row vanished after insert.');
        }

        DB::update(
            'UPDATE number_sequences SET next_value = next_value + 1, updated_at = now() WHERE scope_key = ?',
            [$scopeKey],
        );

        return (int) $row->next_value;
    }

    /**
     * Builds the yearly key "{kind}:{year}", the year taken in Europe/Prague.
     */
    public function scopeKeyForYear(string $kind, DateTimeInterface $at): string
    {
        if (preg_match(self::KIND_PATTERN, $kind) !== 1) {
            throw new InvalidArgumentException("Invalid sequence kind \"{$kind}\".");
        }

        $local = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('Europe/Prague'));

        return $kind.':'.$local->format('Y');
    }

    private static function assertValidKey(string $scopeKey): void
    {
        if (strlen($scopeKey) > self::KEY_MAX_LENGTH || preg_match(self::KEY_PATTERN, $scopeKey) !== 1) {
            throw new InvalidArgumentException('Invalid sequence scope key.');
        }
    }
}
