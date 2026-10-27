<?php

declare(strict_types=1);

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Numbering\NumberPattern;
use App\Domain\Settings\Numbering\ResetPeriod;

/*
 * The token grammar of document numbers (D-05), without the application.
 * Dates are written with separators; long digit runs are assembled from
 * fragments.
 */

/**
 * The parse failure reason of a pattern, or null when it parses.
 */
function patternReason(string $pattern, DocumentKind $kind): ?string
{
    try {
        NumberPattern::parse($pattern, $kind);
    } catch (InvalidNumberPattern $e) {
        return $e->reason;
    }

    return null;
}

function pragueNoon(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date.' 12:00:00', new DateTimeZone('Europe/Prague'));
}

it('accepts the documented valid patterns', function (string $pattern, DocumentKind $kind): void {
    expect(patternReason($pattern, $kind))->toBeNull()
        ->and(NumberPattern::parse($pattern, $kind)->pattern())->toBe($pattern);
})->with([
    'default invoice' => ['{YYYY}{NNNN}', DocumentKind::Invoice],
    'two-digit year' => ['{YY}{NNNNNN}', DocumentKind::Invoice],
    'year, month and counter' => ['{YYYY}{MM}{NNN}', DocumentKind::Invoice],
    'counter only' => ['{NNNNNN}', DocumentKind::Invoice],
    'ten characters exactly' => ['{YYYY}{NNNNNN}', DocumentKind::Invoice],
    'default proforma' => ['{YYYY}9{NNNN}', DocumentKind::Proforma],
    'proforma of digits only, ten characters exactly' => ['{YYYY}{NNNNNN}', DocumentKind::Proforma],
    'credit note with prefix and slash' => ['ZF-{YYYY}/{NNNN}', DocumentKind::CreditNote],
    'credit note with dots and underscore' => ['CN_{YY}.{N}', DocumentKind::CreditNote],
    'thirty-two characters exactly' => [str_repeat('A', 29).'{N}', DocumentKind::CreditNote],
    'task' => ['{KEY}-{N}', DocumentKind::Task],
    'default cash receipt' => ['PPD{YYYY}{NNNN}', DocumentKind::CashReceipt],
    'cash receipt with a dash and a slash' => ['PPD-{YYYY}/{NNNN}', DocumentKind::CashReceipt],
]);

it('refuses a pattern with the named reason', function (string $pattern, DocumentKind $kind, string $reason): void {
    expect(patternReason($pattern, $kind))->toBe($reason);
})->with([
    'no counter' => ['{YYYY}', DocumentKind::Invoice, 'counter_count'],
    'two counters' => ['{N}{NN}', DocumentKind::Invoice, 'counter_count'],
    'empty pattern' => ['', DocumentKind::Invoice, 'counter_count'],
    'month without year' => ['{MM}{NNNN}', DocumentKind::Invoice, 'month_without_year'],
    'unknown token' => ['{XX}{NNNN}', DocumentKind::Invoice, 'unknown_token'],
    'empty token' => ['{}{NNNN}', DocumentKind::Invoice, 'unknown_token'],
    'lower-case counter token' => ['{YYYY}{nnnn}', DocumentKind::Invoice, 'unknown_token'],
    'eleven counter digits' => ['{NNNNNNNNNNN}', DocumentKind::Proforma, 'unknown_token'],
    'nested braces' => ['{{YYYY}{N}', DocumentKind::Proforma, 'unknown_token'],
    'key token in a document pattern' => ['{KEY}-{N}', DocumentKind::Invoice, 'unknown_token'],
    'duplicate year' => ['{YYYY}{YYYY}{N}', DocumentKind::Invoice, 'duplicate_token'],
    'both year tokens' => ['{YYYY}{YY}{N}', DocumentKind::Proforma, 'duplicate_token'],
    'duplicate month' => ['{YYYY}{MM}{MM}{N}', DocumentKind::Proforma, 'duplicate_token'],
    'unclosed token' => ['{YYYY', DocumentKind::Invoice, 'unclosed_token'],
    'unclosed counter' => ['{YYYY}{N', DocumentKind::Proforma, 'unclosed_token'],
    'letters in an invoice' => ['INV{YYYY}{NNNN}', DocumentKind::Invoice, 'invoice_digits'],
    'dash in an invoice' => ['{YYYY}-{NNNN}', DocumentKind::Invoice, 'invoice_digits'],
    'too long minimal invoice' => ['{YYYY}{MM}{NNNNNNN}', DocumentKind::Invoice, 'invoice_length'],
    'letters in a proforma' => ['ZF{YYYY}{NNNN}', DocumentKind::Proforma, 'proforma_digits'],
    'dash in a proforma' => ['{YYYY}-{NNNN}', DocumentKind::Proforma, 'proforma_digits'],
    'slash and letters in a proforma' => ['ZF-{YYYY}/{NNNN}', DocumentKind::Proforma, 'proforma_digits'],
    'too long minimal proforma' => ['{YYYY}{MM}{NNNNNNN}', DocumentKind::Proforma, 'proforma_length'],
    'credit note without a counter' => ['CN{YYYY}', DocumentKind::CreditNote, 'counter_count'],
    'cash receipt without a counter' => ['PPD{YYYY}', DocumentKind::CashReceipt, 'counter_count'],
    'cash receipt with a space' => ['PPD {YYYY}{NNNN}', DocumentKind::CashReceipt, 'literal'],
    'the cash receipt default with letters in an invoice' => ['PPD{YYYY}{NNNN}', DocumentKind::Invoice, 'invoice_digits'],
    'the cash receipt default with letters in a proforma' => ['PPD{YYYY}{NNNN}', DocumentKind::Proforma, 'proforma_digits'],
    'thirty-three characters' => [str_repeat('A', 30).'{N}', DocumentKind::Proforma, 'too_long'],
    'space' => ['ZF {YYYY}{N}', DocumentKind::CreditNote, 'literal'],
    'semicolon' => ['ZF;{YYYY}{N}', DocumentKind::CreditNote, 'literal'],
    'stray closing brace' => ['ZF}{N}', DocumentKind::CreditNote, 'literal'],
    'space in a proforma' => ['{YYYY} {N}', DocumentKind::Proforma, 'literal'],
    'task with a year' => ['{YYYY}-{N}', DocumentKind::Task, 'task_fixed'],
    'task with another separator' => ['{KEY}/{N}', DocumentKind::Task, 'task_fixed'],
    'task with a padded counter' => ['{KEY}-{NNN}', DocumentKind::Task, 'task_fixed'],
]);

it('refuses a ten thousand character input as too long, quickly', function (): void {
    $start = hrtime(true);
    $reason = patternReason(str_repeat('{', 10000), DocumentKind::Proforma);
    $milliseconds = (hrtime(true) - $start) / 1_000_000;

    expect($reason)->toBe('too_long')
        ->and($milliseconds)->toBeLessThan(50.0);
});

it('derives the reset period from the date tokens', function (string $pattern, ResetPeriod $expected, string $key): void {
    $parsed = NumberPattern::parse($pattern, DocumentKind::Invoice);

    expect($parsed->resetPeriod())->toBe($expected)
        ->and($parsed->scopeKey(pragueNoon('2026-03-09')))->toBe($key);
})->with([
    'four-digit year' => ['{YYYY}{NNNN}', ResetPeriod::Yearly, 'invoice:2026'],
    'two-digit year' => ['{YY}{NNNN}', ResetPeriod::Yearly, 'invoice:2026'],
    'year and month' => ['{YYYY}{MM}{N}', ResetPeriod::Monthly, 'invoice:2026-03'],
    'no date' => ['{NNNN}', ResetPeriod::Never, 'invoice:all'],
]);

it('takes the month in Europe/Prague as well', function (): void {
    $parsed = NumberPattern::parse('{YYYY}{MM}{N}', DocumentKind::Invoice);
    $utc = new DateTimeImmutable('2026-03-31 23:30:00', new DateTimeZone('UTC'));

    expect($parsed->scopeKey($utc))->toBe('invoice:2026-04')
        ->and($parsed->format(5, $utc))->toBe('202604'.'5');
});

it('writes dates and the padded counter into the number', function (): void {
    $at = pragueNoon('2026-03-09');

    expect(NumberPattern::parse('{YYYY}{NNNN}', DocumentKind::Invoice)->format(7, $at))->toBe('2026'.'0007')
        ->and(NumberPattern::parse('{YY}{NNNNNN}', DocumentKind::Invoice)->format(42, $at))->toBe('26'.'000042')
        ->and(NumberPattern::parse('{YYYY}{MM}{NNN}', DocumentKind::Invoice)->format(3, $at))->toBe('202603'.'003')
        ->and(NumberPattern::parse('{YYYY}9{NNNN}', DocumentKind::Proforma)->format(12, $at))->toBe('2026'.'9'.'0012')
        ->and(NumberPattern::parse('ZF-{YYYY}/{NNNN}', DocumentKind::CreditNote)->format(12, $at))->toBe('ZF-2026/0012');
});

it('refuses to write an invoice number longer than ten characters instead of truncating it', function (): void {
    $pattern = NumberPattern::parse('{YYYY}{NNNN}', DocumentKind::Invoice);
    $at = pragueNoon('2026-03-09');

    expect($pattern->format(999999, $at))->toBe('2026'.'999999')
        ->and(fn () => $pattern->format(1000000, $at))->toThrow(OverflowException::class);
});

it('refuses to write a proforma number longer than ten characters instead of truncating it', function (): void {
    $pattern = NumberPattern::parse('{YYYY}9{NNNN}', DocumentKind::Proforma);
    $at = pragueNoon('2026-03-09');

    expect($pattern->format(99999, $at))->toBe('2026'.'9'.'99999')
        ->and(fn () => $pattern->format(100000, $at))->toThrow(OverflowException::class);
});

it('lets the counter of a credit note grow past its pad width without truncation', function (): void {
    $pattern = NumberPattern::parse('ZF-{NNNN}', DocumentKind::CreditNote);

    expect($pattern->format(12345, pragueNoon('2026-03-09')))->toBe('ZF-12345');
});

it('writes the task number as the project key and the counter', function (): void {
    $pattern = NumberPattern::parse('{KEY}-{N}', DocumentKind::Task);

    expect($pattern->format(1, pragueNoon('2026-03-09'), 'ABC'))->toBe('ABC-1')
        ->and($pattern->format(120, pragueNoon('2026-03-09'), 'ABC'))->toBe('ABC-120');
});

it('refuses a task number without a valid project key', function (?string $key): void {
    $pattern = NumberPattern::parse('{KEY}-{N}', DocumentKind::Task);

    expect(fn () => $pattern->format(1, pragueNoon('2026-03-09'), $key))->toThrow(InvalidArgumentException::class);
})->with([
    'missing' => [null],
    'lower case' => ['abc'],
    'too short' => ['A'],
    'too long' => ['TOOLONGKEY'],
    'digits' => ['AB1'],
]);

it('has no date scope key for a task, because its counter is per project', function (): void {
    expect(fn () => NumberPattern::parse('{KEY}-{N}', DocumentKind::Task)->scopeKey(pragueNoon('2026-03-09')))
        ->toThrow(LogicException::class);
});

it('refuses a counter value below one', function (): void {
    expect(fn () => NumberPattern::parse('{NNNN}', DocumentKind::Invoice)->format(0, pragueNoon('2026-03-09')))
        ->toThrow(InvalidArgumentException::class);
});

it('gives the cash receipt its own default pattern and no variable symbol', function (): void {
    expect(DocumentKind::CashReceipt->value)->toBe('cash_receipt')
        ->and(DocumentKind::CashReceipt->defaultPattern())->toBe('PPD{YYYY}{NNNN}')
        ->and(DocumentKind::CashReceipt->carriesVariableSymbol())->toBeFalse();
});

it('keeps the cash receipt counter in a scope of its own, per issue year in Prague', function (): void {
    $parsed = NumberPattern::parse('PPD{YYYY}{NNNN}', DocumentKind::CashReceipt);
    $invoice = NumberPattern::parse('{YYYY}{NNNN}', DocumentKind::Invoice);

    expect($parsed->resetPeriod())->toBe(ResetPeriod::Yearly)
        ->and($parsed->scopeKey(pragueNoon('2026-10-27')))->toBe('cash_receipt:2026')
        ->and($parsed->scopeKey(pragueNoon('2026-10-27')))->not->toBe($invoice->scopeKey(pragueNoon('2026-10-27')));
});

it('writes the cash receipt number with letters and does not cap it at ten characters', function (): void {
    $parsed = NumberPattern::parse('PPD{YYYY}{NNNN}', DocumentKind::CashReceipt);
    $at = pragueNoon('2026-10-27');

    expect($parsed->format(1, $at))->toBe('PPD2026'.'0001')
        ->and($parsed->format(12345, $at))->toBe('PPD2026'.'12345');
});
