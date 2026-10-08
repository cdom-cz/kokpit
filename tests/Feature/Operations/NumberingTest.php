<?php

declare(strict_types=1);

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Numbering\NumberPattern;
use App\Domain\Settings\Rules\NumberPatternRule;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

/**
 * The stored pattern values, straight from the table.
 *
 * @return array<string, string>
 */
function storedPatterns(): array
{
    return SettingsProperty::query()->where('group', 'numbering')->orderBy('name')->get()
        ->mapWithKeys(fn (SettingsProperty $row): array => [$row->name => (string) json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR)])
        ->all();
}

it('numbers tasks per project as KEY-N from the task scope key', function (): void {
    $numbering = app(DocumentNumbering::class);
    $project = fake()->uuid();
    $other = fake()->uuid();

    $numbers = DB::transaction(fn (): array => [
        $numbering->nextTaskNumber($project, 'ABC'),
        $numbering->nextTaskNumber($project, 'ABC'),
        $numbering->nextTaskNumber($other, 'XYZ'),
    ]);

    expect($numbers)->toBe(['ABC-1', 'ABC-2', 'XYZ-1'])
        ->and(counterRows())->toContain(['task:'.$project, 3], ['task:'.$other, 2]);
});

it('refuses a project key that is not two to six capital letters', function (string $key): void {
    $numbering = app(DocumentNumbering::class);

    expect(fn () => DB::transaction(fn (): string => $numbering->nextTaskNumber(fake()->uuid(), $key)))
        ->toThrow(InvalidArgumentException::class)
        ->and(counterRows())->toBe([]);
})->with(['abc', 'TOOLONGKEY', 'A', 'AB1', '']);

it('refuses a task number outside a transaction', function (): void {
    $numbering = app(DocumentNumbering::class);
    $project = fake()->uuid();
    $level = DB::transactionLevel();

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        expect(fn () => $numbering->nextTaskNumber($project, 'ABC'))->toThrow(LogicException::class);
    } finally {
        while (DB::transactionLevel() < $level) {
            DB::beginTransaction();
        }
    }
});

it('does not allocate a task number through the document entry points', function (): void {
    $numbering = app(DocumentNumbering::class);
    $at = utcInstant('2026-06-15 10:00:00');

    expect(fn () => DB::transaction(fn (): string => $numbering->next(DocumentKind::Task, $at)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $numbering->preview(DocumentKind::Task, null, $at))->toThrow(InvalidArgumentException::class);
});

it('continues the counter when the pattern changes within the same reset period', function (): void {
    $at = utcInstant('2026-06-15 10:00:00');
    $first = DB::transaction(fn (): string => app(DocumentNumbering::class)->next(DocumentKind::Invoice, $at));

    $numbering = numberingWith(['invoice_pattern' => '{YYYY}{NNNNN}']);
    $second = DB::transaction(fn (): string => $numbering->next(DocumentKind::Invoice, $at));

    expect($first)->toBe('2026'.'0001')
        ->and($second)->toBe('2026'.'00002')
        ->and($first)->toBe('2026'.'0001')
        ->and(counterRows())->toBe([['invoice:2026', 3]]);
});

it('keeps the counter when only the year token form changes', function (): void {
    $at = utcInstant('2026-06-15 10:00:00');
    DB::transaction(fn (): string => app(DocumentNumbering::class)->next(DocumentKind::Invoice, $at));

    $numbering = numberingWith(['invoice_pattern' => '{YY}{NNNN}']);
    $second = DB::transaction(fn (): string => $numbering->next(DocumentKind::Invoice, $at));

    expect($second)->toBe('26'.'0002');
});

it('starts a new series at 1 when the reset period changes and leaves the old counter alone', function (): void {
    $at = utcInstant('2026-06-15 10:00:00');
    DB::transaction(fn (): string => app(DocumentNumbering::class)->next(DocumentKind::Invoice, $at));
    DB::transaction(fn (): string => app(DocumentNumbering::class)->next(DocumentKind::Invoice, $at));

    $numbering = numberingWith(['invoice_pattern' => '{YYYY}{MM}{NNNN}']);
    $monthly = DB::transaction(fn (): string => $numbering->next(DocumentKind::Invoice, $at));

    expect($monthly)->toBe('202606'.'0001')
        ->and(counterRows())->toBe([['invoice:2026', 3], ['invoice:2026-06', 2]]);
});

it('throws instead of truncating when a number no longer fits ten characters, and gives the number back', function (): void {
    DB::table('number_sequences')->insert(['scope_key' => 'invoice:2026', 'next_value' => 1000000]);
    $numbering = numberingWith(['invoice_pattern' => '{YYYY}{NNNNNN}']);

    expect(fn () => DB::transaction(fn (): string => $numbering->next(DocumentKind::Invoice, utcInstant('2026-06-15 10:00:00'))))
        ->toThrow(OverflowException::class)
        ->and(counterRows())->toBe([['invoice:2026', 1000000]]);
});

it('previews a candidate pattern without storing it and without touching a counter', function (): void {
    $numbering = app(DocumentNumbering::class);
    $at = utcInstant('2026-06-15 10:00:00');
    DB::transaction(fn (): string => $numbering->next(DocumentKind::Proforma, $at));
    $before = counterRows();
    $stored = storedPatterns();

    expect($numbering->preview(DocumentKind::Proforma, 'ZF-{YYYY}/{NNNN}', $at))->toBe('ZF-2026/0002')
        ->and($numbering->preview(DocumentKind::Proforma, 'ZF-{YYYY}/{NNNN}', $at))->toBe('ZF-2026/0002')
        ->and($numbering->preview(DocumentKind::Proforma, '{YYYY}{MM}{NN}', $at))->toBe('202606'.'01')
        ->and(counterRows())->toBe($before)
        ->and(storedPatterns())->toBe($stored);
});

it('refuses to preview a candidate pattern the grammar refuses', function (): void {
    expect(fn () => app(DocumentNumbering::class)->preview(DocumentKind::Invoice, 'INV{NNNN}'))
        ->toThrow(InvalidNumberPattern::class)
        ->and(counterRows())->toBe([]);
});

it('has a Czech message for every refusal reason', function (string $reason): void {
    $key = 'kokpit.settings.numbering.errors.'.$reason;

    expect(Lang::has($key))->toBeTrue()
        ->and(__($key))->not->toBe($key)
        ->and(__($key))->not->toBe('');
})->with([
    'unknown_token', 'counter_count', 'month_without_year', 'literal', 'too_long',
    'invoice_digits', 'invoice_length', 'task_fixed', 'duplicate_token', 'unclosed_token',
]);

it('lets the rule pass a valid pattern and fail an invalid one with the Czech reason', function (): void {
    $valid = Validator::make(['p' => '{YYYY}{NNNN}'], ['p' => [new NumberPatternRule(DocumentKind::Invoice)]]);
    $invalid = Validator::make(['p' => '{YYYY}'], ['p' => [new NumberPatternRule(DocumentKind::Invoice)]]);
    $notText = Validator::make(['p' => ['x']], ['p' => [new NumberPatternRule(DocumentKind::Invoice)]]);

    expect($valid->passes())->toBeTrue()
        ->and($invalid->fails())->toBeTrue()
        ->and($invalid->errors()->first('p'))->toBe(__('kokpit.settings.numbering.errors.counter_count'))
        ->and($notText->fails())->toBeTrue();
});

it('refuses an invalid pattern written outside any form with the Czech reason and stores nothing', function (string $property, string $value, string $reason): void {
    $stored = storedPatterns();
    $settings = app(NumberingSettings::class);
    $settings->{$property} = $value;

    try {
        $settings->save();
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->getMessage())->toBe(__('kokpit.settings.numbering.errors.'.$reason))
            ->and($e->errors())->toHaveKey($property);
    }

    expect(storedPatterns())->toBe($stored);
})->with([
    'invoice without a counter' => ['invoice_pattern', '{YYYY}', 'counter_count'],
    'invoice with letters' => ['invoice_pattern', 'INV{YYYY}{NNNN}', 'invoice_digits'],
    'proforma with a space' => ['proforma_pattern', 'ZF {N}', 'literal'],
    'credit note month without year' => ['credit_note_pattern', '{MM}{N}', 'month_without_year'],
    'task pattern other than KEY-N' => ['task_pattern', '{YYYY}-{N}', 'task_fixed'],
    'oversized invoice pattern' => ['invoice_pattern', '{YYYY}{NNNN}{NNNN}{NNNN}{NNNN}{NNNN}', 'too_long'],
]);

it('stores valid patterns for every kind and reads them back as parsed patterns', function (): void {
    $settings = app(NumberingSettings::class);
    $settings->proforma_pattern = 'ZF-{YYYY}/{NNNN}';
    $settings->credit_note_pattern = 'CN{YY}{MM}{NNN}';
    $settings->save();
    app()->forgetScopedInstances();
    $fresh = app(NumberingSettings::class);

    expect(storedPatterns())->toBe([
        'credit_note_pattern' => 'CN{YY}{MM}{NNN}',
        'invoice_pattern' => '{YYYY}{NNNN}',
        'proforma_pattern' => 'ZF-{YYYY}/{NNNN}',
        'task_pattern' => '{KEY}-{N}',
    ])
        ->and($fresh->patternFor(DocumentKind::Proforma)->pattern())->toBe('ZF-{YYYY}/{NNNN}')
        ->and($fresh->patternFor(DocumentKind::Task)->pattern())->toBe('{KEY}-{N}');
});
