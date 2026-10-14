<?php

declare(strict_types=1);

use App\Domain\Reports\Exports\SpreadsheetCells;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use Tests\TestCase;

/*
 * Spreadsheet-safe cells of the report exports (RE-03, D-09; research Pitfall 1): user text never becomes a
 * live formula. The dangerous starts are assembled from fragments, so no line below holds a ready payload.
 */

// The configured decimal sign is read from the application configuration.
uses(TestCase::class);

/**
 * The dangerous texts: each starts with one of the characters a spreadsheet reads as a formula or a command.
 *
 * @return array<string, array{string}>
 */
dataset('dangerous texts', function (): array {
    $tail = 'SUM('.'A1'.')';

    return [
        'equals sign' => ['='.$tail],
        'plus sign' => ['+'.$tail],
        'minus sign' => ['-'.'5'],
        'at sign' => ['@'.'x'],
        'tab' => ["\t".'A'],
        'carriage return' => ["\r".'A'],
        'equals sign after leading spaces' => ['  ='.'1+1'],
        'minus sign after one space' => [' -'.'1'],
    ];
});

it('prefixes a text that starts like a formula with an apostrophe and keeps it an explicit string cell', function (string $text): void {
    $cell = SpreadsheetCells::text($text);

    expect($cell)->toBeInstanceOf(StringCell::class)
        ->and($cell->getValue())->toBe("'".$text);
})->with('dangerous texts');

it('keeps an ordinary text unchanged', function (string $text): void {
    expect(SpreadsheetCells::text($text)->getValue())->toBe($text);
})->with(['Example s.r.o.', '', 'Landing page', '  indented text', 'a=b', 'e-mail', '2026-09-15', 'Žluťoučký kůň']);

it('turns a missing text into an empty string cell', function (): void {
    $cell = SpreadsheetCells::text(null);

    expect($cell)->toBeInstanceOf(StringCell::class)
        ->and($cell->getValue())->toBe('');
});

it('writes a decimal with the configured decimal sign', function (): void {
    expect(SpreadsheetCells::decimal('1234.50')->getValue())->toBe('1234,50')
        ->and(SpreadsheetCells::decimal('0.0169')->getValue())->toBe('0,0169')
        ->and(SpreadsheetCells::decimal('-3.00')->getValue())->toBe('-3,00')
        ->and(SpreadsheetCells::decimal('1500')->getValue())->toBe('1500');

    config(['kokpit.exports.csv.decimal_separator' => '.']);

    expect(SpreadsheetCells::decimal('1234.50')->getValue())->toBe('1234.50');
});

it('falls back to the decimal comma when the configured sign is empty', function (): void {
    config(['kokpit.exports.csv.decimal_separator' => '']);

    expect(SpreadsheetCells::decimal('1.5')->getValue())->toBe('1,5');
});

it('writes whole seconds as a numeric cell', function (): void {
    $cell = SpreadsheetCells::integer(5400);

    expect($cell)->toBeInstanceOf(NumericCell::class)
        ->and($cell->getValue())->toBe(5400);
});

it('writes an exact decimal string as a numeric cell, a negative one included', function (string $exact, float $expected): void {
    $cell = SpreadsheetCells::number($exact);

    expect($cell)->toBeInstanceOf(NumericCell::class)
        ->and($cell->getValue())->toBe($expected);
})->with([
    'whole' => ['5400', 5400.0],
    'two decimals' => ['180.00', 180.0],
    'four decimals' => ['0.0169', 0.0169],
    'negative' => ['-3.25', -3.25],
]);

it('refuses a number that is not a plain decimal, so text can never become a numeric or formula cell', function (string $text): void {
    expect(fn () => SpreadsheetCells::number($text))->toThrow(InvalidArgumentException::class);
})->with([
    'formula' => ['='.'1+1'],
    'text' => ['abc'],
    'decimal comma' => ['1,5'],
    'empty' => [''],
    'exponent' => ['1e5'],
    'trailing newline' => ["1\n"],
]);

it('proves the canary: the value-inferring factory of OpenSpout makes a formula of the same text', function (): void {
    $canary = '='.'1+1';

    // If the safe path were ever replaced by inference, the cell below would be a formula cell.
    expect(Cell::fromValue($canary))->toBeInstanceOf(FormulaCell::class)
        ->and(SpreadsheetCells::text($canary))->toBeInstanceOf(StringCell::class)
        ->and(SpreadsheetCells::text($canary))->not->toBeInstanceOf(FormulaCell::class);
});
