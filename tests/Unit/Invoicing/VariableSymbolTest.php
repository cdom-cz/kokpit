<?php

declare(strict_types=1);

use App\Domain\Invoicing\Support\VariableSymbol;
use App\Domain\Settings\Numbering\DocumentKind;

/*
 * The variable symbol is the document number itself for an invoice and a
 * proforma and absent for a credit note (R-07-2). Numbers are assembled from
 * fragments so no line carries a long digit run.
 */

it('returns the number itself as the symbol of an invoice and a proforma', function (DocumentKind $kind, string $number): void {
    expect(VariableSymbol::fromNumber($kind, $number))->toBe($number);
})->with([
    'default invoice' => [DocumentKind::Invoice, '2026'.'0042'],
    'default proforma with nine digits' => [DocumentKind::Proforma, '2026'.'9'.'0042'],
    'a single digit' => [DocumentKind::Invoice, '7'],
    'ten digits exactly' => [DocumentKind::Proforma, '2026'.'123456'],
]);

it('gives a credit note no variable symbol, whatever its number looks like', function (string $number): void {
    expect(VariableSymbol::fromNumber(DocumentKind::CreditNote, $number))->toBeNull();
})->with([
    'digits' => ['2026'.'0001'],
    'letters and separators' => ['CN-2026/12'],
    'empty' => [''],
]);

it('refuses a number that is not 1 to 10 digits instead of emitting a bad symbol', function (DocumentKind $kind, string $number): void {
    expect(fn () => VariableSymbol::fromNumber($kind, $number))->toThrow(LogicException::class);
})->with([
    'letter in an invoice' => [DocumentKind::Invoice, '2026'.'00A2'],
    'eleven digits' => [DocumentKind::Invoice, '2026'.'1234567'],
    'dash in a proforma' => [DocumentKind::Proforma, '2026-'.'0042'],
    'empty' => [DocumentKind::Invoice, ''],
    'trailing newline' => [DocumentKind::Proforma, '2026'."\n"],
    'space' => [DocumentKind::Invoice, ' 2026'],
]);

it('has no variable symbol for a task number', function (): void {
    expect(fn () => VariableSymbol::fromNumber(DocumentKind::Task, 'ABC-1'))->toThrow(LogicException::class);
});
