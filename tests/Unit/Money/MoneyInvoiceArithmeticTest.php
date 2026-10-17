<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;

it('prices a time-generated quantity: 12.5847 h at 1500.00 CZK is 18877.05 CZK', function () {
    // The same number PostgreSQL round(quantity * unit_price_minor) gives for the invoice_items CHECK.
    expect(Money::ofMinor(150000, 'CZK')->times('12.5847')->minor)->toBe(1887705);
});

it('multiplies by a decimal factor with one rounding', function (int $minor, string $factor, int $expected) {
    expect(Money::ofMinor($minor, 'CZK')->times($factor)->minor)->toBe($expected);
})->with([
    'a third of a unit' => [100000, '1.3333', 133330],
    'half rounds away from zero' => [3, '0.5', 2],
    'negative half rounds away from zero' => [3, '-0.5', -2],
    'a negative factor flips the sign' => [150000, '-2.0000', -300000],
    'zero' => [150000, '0', 0],
]);

it('refuses a malformed factor', function (string $factor) {
    Money::ofMinor(100, 'CZK')->times($factor);
})->throws(InvalidArgumentException::class)->with([
    'too many fraction digits' => ['1.23456'],
    'exponent' => ['1e2'],
    'empty' => [''],
    'comma' => ['1,5'],
    'plus sign' => ['+1'],
]);

it('refuses a product that does not fit into an integer', function () {
    Money::ofMinor(PHP_INT_MAX, 'CZK')->times('9999999999');
})->throws(OverflowException::class);
