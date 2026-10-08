<?php

declare(strict_types=1);

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Numbering\NumberPattern;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * Document numbering (D-05): the stored pattern drives the scope key of the
 * Phase 2 allocator and the written form, and a preview never consumes a number.
 *
 * Dates are written with separators; expected numbers are assembled from
 * fragments so no line carries a long digit run.
 */

/**
 * An instant given in UTC.
 */
function utcInstant(string $when): DateTimeImmutable
{
    return new DateTimeImmutable($when, new DateTimeZone('UTC'));
}

/**
 * Everything in the counter table, ordered, for before/after comparisons.
 *
 * @return list<array{string, int}>
 */
function counterRows(): array
{
    return DB::table('number_sequences')->orderBy('scope_key')->get()
        ->map(fn (object $row): array => [(string) $row->scope_key, (int) $row->next_value])
        ->all();
}

/**
 * Saves new patterns as the Admin and returns a service that reads them.
 *
 * @param  array<string, string>  $patterns
 */
function numberingWith(array $patterns): DocumentNumbering
{
    $settings = app(NumberingSettings::class);

    foreach ($patterns as $property => $value) {
        $settings->{$property} = $value;
    }

    $settings->save();
    app()->forgetScopedInstances();

    return app(DocumentNumbering::class);
}

beforeEach(function (): void {
    $this->actingAs(Canary::admin());
});

it('peeks 1 without a row and never inserts one', function (): void {
    $allocator = new SequenceAllocator;

    expect($allocator->peek('invoice:2026'))->toBe(1)
        ->and($allocator->peek('invoice:2026'))->toBe(1)
        ->and(counterRows())->toBe([]);
});

it('peeks the next value after next() ran twice, without changing it', function (): void {
    $allocator = new SequenceAllocator;

    DB::transaction(function () use ($allocator): void {
        $allocator->next('invoice:2026');
        $allocator->next('invoice:2026');
    });

    expect($allocator->peek('invoice:2026'))->toBe(3)
        ->and($allocator->peek('invoice:2026'))->toBe(3)
        ->and(counterRows())->toBe([['invoice:2026', 3]]);
});

it('peeks outside a transaction without an error', function (): void {
    $level = DB::transactionLevel();

    // RefreshDatabase wraps each test in a transaction; leave it to prove the point.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        expect((new SequenceAllocator)->peek('invoice:2026'))->toBe(1);
    } finally {
        while (DB::transactionLevel() < $level) {
            DB::beginTransaction();
        }
    }
});

it('refuses to peek a malformed key', function (): void {
    expect(fn () => (new SequenceAllocator)->peek('Invoice 2026'))->toThrow(InvalidArgumentException::class);
});

it('maps the default invoice pattern onto the allocator yearly key, also across the Prague new year', function (string $when): void {
    $at = utcInstant($when);

    $pattern = NumberPattern::parse('{YYYY}{NNNN}', DocumentKind::Invoice);

    expect($pattern->scopeKey($at))->toBe((new SequenceAllocator)->scopeKeyForYear('invoice', $at));
})->with([
    'mid-year' => '2026-06-15 10:00:00',
    'new year in Prague already' => '2026-12-31 23:30:00',
]);

it('takes the year in Europe/Prague, so 31 December 23:30 UTC is already the next year', function (): void {
    $pattern = NumberPattern::parse('{YYYY}{NNNN}', DocumentKind::Invoice);

    expect($pattern->scopeKey(utcInstant('2026-12-31 23:30:00')))->toBe('invoice:2027')
        ->and($pattern->format(7, utcInstant('2026-12-31 23:30:00')))->toBe('2027'.'0007');
});

it('previews the next number twice with the same result and leaves the counters untouched', function (): void {
    $at = utcInstant('2026-06-15 10:00:00');
    $numbering = app(DocumentNumbering::class);

    DB::transaction(fn (): string => $numbering->next(DocumentKind::Invoice, $at));
    $before = counterRows();

    $first = $numbering->preview(DocumentKind::Invoice, null, $at);
    $second = $numbering->preview(DocumentKind::Invoice, null, $at);

    expect($first)->toBe('2026'.'0002')
        ->and($second)->toBe($first)
        ->and(counterRows())->toBe($before);
});

it('previews 0001 on an empty counter table without inserting a row', function (): void {
    $preview = app(DocumentNumbering::class)->preview(DocumentKind::Invoice, null, utcInstant('2026-06-15 10:00:00'));

    expect($preview)->toBe('2026'.'0001')
        ->and(counterRows())->toBe([]);
});

it('allocates the previewed number inside a transaction and previews the following one afterwards', function (): void {
    $at = utcInstant('2026-06-15 10:00:00');
    $numbering = app(DocumentNumbering::class);

    $previewed = $numbering->preview(DocumentKind::Invoice, null, $at);
    $allocated = DB::transaction(fn (): string => $numbering->next(DocumentKind::Invoice, $at));

    expect($allocated)->toBe($previewed)
        ->and($numbering->preview(DocumentKind::Invoice, null, $at))->toBe('2026'.'0002');
});

it('refuses to allocate outside a transaction', function (): void {
    $numbering = app(DocumentNumbering::class);
    // Load the settings while the test's Admin still exists; the rollback below removes that user.
    $numbering->preview(DocumentKind::Invoice, null, utcInstant('2026-06-15 10:00:00'));
    $level = DB::transactionLevel();

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        expect(fn () => $numbering->next(DocumentKind::Invoice, utcInstant('2026-06-15 10:00:00')))
            ->toThrow(LogicException::class);
    } finally {
        while (DB::transactionLevel() < $level) {
            DB::beginTransaction();
        }
    }
});

it('keeps separate counters per document kind', function (): void {
    $at = utcInstant('2026-06-15 10:00:00');
    $numbering = app(DocumentNumbering::class);

    $numbers = DB::transaction(fn (): array => [
        $numbering->next(DocumentKind::Invoice, $at),
        $numbering->next(DocumentKind::Proforma, $at),
        $numbering->next(DocumentKind::CreditNote, $at),
        $numbering->next(DocumentKind::Invoice, $at),
    ]);

    expect($numbers)->toBe(['2026'.'0001', '2026'.'0001', '2026'.'0001', '2026'.'0002'])
        ->and(counterRows())->toBe([['credit_note:2026', 2], ['invoice:2026', 3], ['proforma:2026', 2]]);
});

it('writes a saved invoice pattern with five counter digits into the next number', function (): void {
    $numbering = numberingWith(['invoice_pattern' => '{YYYY}{NNNNN}']);

    $number = DB::transaction(fn (): string => $numbering->next(DocumentKind::Invoice, utcInstant('2026-06-15 10:00:00')));

    expect($number)->toBe('2026'.'00001');
});
