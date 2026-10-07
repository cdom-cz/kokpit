<?php

declare(strict_types=1);

use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function allocator(): SequenceAllocator
{
    return new SequenceAllocator;
}

it('hands out 1, 2, 3 for a key on first use', function () {
    $numbers = DB::transaction(fn (): array => [
        allocator()->next('invoice:2026'),
        allocator()->next('invoice:2026'),
        allocator()->next('invoice:2026'),
    ]);

    expect($numbers)->toBe([1, 2, 3]);
});

it('stores the next number to hand out, not the last one issued', function () {
    DB::transaction(function (): void {
        allocator()->next('invoice:2026');
        allocator()->next('invoice:2026');
    });

    expect((int) DB::table('number_sequences')->where('scope_key', 'invoice:2026')->value('next_value'))->toBe(3);
});

it('continues from a row an importer set directly', function () {
    DB::table('number_sequences')->insert(['scope_key' => 'invoice:2025', 'next_value' => 120]);

    $number = DB::transaction(fn (): int => allocator()->next('invoice:2025'));

    expect($number)->toBe(120);
});

it('counts two keys independently', function () {
    $project = 'task:'.fake()->uuid();

    $numbers = DB::transaction(fn (): array => [
        allocator()->next('invoice:2026'),
        allocator()->next($project),
        allocator()->next('invoice:2026'),
        allocator()->next($project),
    ]);

    expect($numbers)->toBe([1, 1, 2, 2]);
});

it('refuses to run outside a transaction', function () {
    // RefreshDatabase wraps each test in a transaction; leave it to prove the guard.
    $level = DB::transactionLevel();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        expect(fn () => allocator()->next('invoice:2026'))
            ->toThrow(LogicException::class, 'must run inside the caller');
    } finally {
        while (DB::transactionLevel() < $level) {
            DB::beginTransaction();
        }
    }
});

it('gives a number back when the caller transaction rolls back', function () {
    expect(DB::transaction(fn (): int => allocator()->next('invoice:2026')))->toBe(1);

    try {
        DB::transaction(function (): void {
            expect(allocator()->next('invoice:2026'))->toBe(2);

            throw new RuntimeException('caller failed after allocating');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::transaction(fn (): int => allocator()->next('invoice:2026')))->toBe(2);
});

it('rolls a nested savepoint back and reissues the same number', function () {
    $numbers = DB::transaction(function (): array {
        $first = allocator()->next('invoice:2026');

        try {
            DB::transaction(function (): void {
                allocator()->next('invoice:2026');

                throw new RuntimeException('inner failure');
            });
        } catch (RuntimeException) {
            // expected
        }

        return [$first, allocator()->next('invoice:2026')];
    });

    expect($numbers)->toBe([1, 2]);
});

it('rejects a malformed key before touching the database', function (string $key) {
    DB::transaction(function () use ($key): void {
        DB::enableQueryLog();
        DB::flushQueryLog();

        expect(fn () => allocator()->next($key))->toThrow(InvalidArgumentException::class);
        expect(DB::getQueryLog())->toBe([]);

        DB::disableQueryLog();
    });
})->with([
    'upper-case kind' => 'Invoice:2026',
    'no qualifier' => 'invoice',
    'space in qualifier' => 'invoice:20 26',
    'empty' => '',
    'trailing newline' => "invoice:2026\n",
    'too long' => 'invoice:'.str_repeat('x', 200),
]);

it('lets the database reject a malformed key too', function (string $key) {
    $insert = function () use ($key): void {
        DB::transaction(function () use ($key): void {
            DB::insert('INSERT INTO number_sequences (scope_key, next_value) VALUES (?, 1)', [$key]);
        });
    };

    try {
        $insert();
        $state = null;
    } catch (QueryException $e) {
        $state = $e->getCode();
    }

    expect($state)->toBe('23514');
})->with([
    'upper-case kind' => 'Invoice:2026',
    'no qualifier' => 'invoice',
    'space in qualifier' => 'invoice:20 26',
    'empty' => '',
]);

it('refuses a counter below 1 in the database', function () {
    $state = null;

    try {
        DB::transaction(function (): void {
            DB::insert('INSERT INTO number_sequences (scope_key, next_value) VALUES (?, 0)', ['invoice:2026']);
        });
    } catch (QueryException $e) {
        $state = $e->getCode();
    }

    expect($state)->toBe('23514');
});

it('refuses a duplicate scope key in the database', function () {
    $state = null;

    try {
        DB::transaction(function (): void {
            DB::insert('INSERT INTO number_sequences (scope_key) VALUES (?)', ['invoice:2026']);
            DB::insert('INSERT INTO number_sequences (scope_key) VALUES (?)', ['invoice:2026']);
        });
    } catch (QueryException $e) {
        $state = $e->getCode();
    }

    expect($state)->toBe('23505');
});

it('computes the year of a key in Europe/Prague', function () {
    $newYearInPrague = new DateTimeImmutable('2026-12-31 23:30:00', new DateTimeZone('UTC'));
    $stillOldYear = new DateTimeImmutable('2026-12-31 22:59:00', new DateTimeZone('UTC'));

    expect(allocator()->scopeKeyForYear('invoice', $newYearInPrague))->toBe('invoice:2027')
        ->and(allocator()->scopeKeyForYear('invoice', $stillOldYear))->toBe('invoice:2026');
});

it('rejects a malformed kind', function (string $kind) {
    expect(fn () => allocator()->scopeKeyForYear($kind, new DateTimeImmutable))
        ->toThrow(InvalidArgumentException::class);
})->with(['Invoice', 'in voice', 'invoice:x', '', "invoice\n"]);

it('starts the next year at 1 while the old year keeps counting', function () {
    $december = new DateTimeImmutable('2026-12-30 12:00:00', new DateTimeZone('UTC'));
    $january = new DateTimeImmutable('2027-01-02 12:00:00', new DateTimeZone('UTC'));

    $numbers = DB::transaction(function () use ($december, $january): array {
        $allocator = allocator();
        $old = $allocator->scopeKeyForYear('invoice', $december);
        $new = $allocator->scopeKeyForYear('invoice', $january);

        return [$allocator->next($old), $allocator->next($new), $allocator->next($old), $allocator->next($new)];
    });

    expect($numbers)->toBe([1, 1, 2, 2]);
});
