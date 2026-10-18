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

it('negates an amount and tests its sign', function () {
    $amount = Money::ofMinor(1250, 'EUR');

    expect($amount->negated()->minor)->toBe(-1250)
        ->and($amount->negated()->currency)->toBe('EUR')
        ->and($amount->isPositive())->toBeTrue()
        ->and($amount->isNegative())->toBeFalse()
        ->and($amount->negated()->isNegative())->toBeTrue()
        ->and($amount->negated()->isPositive())->toBeFalse()
        ->and(Money::zero('EUR')->isPositive())->toBeFalse()
        ->and(Money::zero('EUR')->isNegative())->toBeFalse();
});

it('refuses to negate the smallest integer', function () {
    Money::ofMinor(PHP_INT_MIN, 'CZK')->negated();
})->throws(OverflowException::class);

it('compares amounts of one currency and refuses two currencies', function () {
    $one = Money::ofMinor(100, 'CZK');
    $two = Money::ofMinor(200, 'CZK');

    expect($one->compareTo($two))->toBe(-1)
        ->and($two->compareTo($one))->toBe(1)
        ->and($one->compareTo(Money::ofMinor(100, 'CZK')))->toBe(0);

    $one->compareTo(Money::ofMinor(100, 'EUR'));
})->throws(InvalidArgumentException::class);

it('converts into CZK exactly like convert()', function () {
    $amount = Money::ofMinor(10000, 'EUR');

    $through = $amount->convertThroughCzk('25.125', 1, 'CZK', '1', 1);

    expect($through->minor)->toBe(251250)
        ->and($through->currency)->toBe('CZK')
        ->and($through->equals($amount->convert('25.125', 'CZK', 1)))->toBeTrue();
});

it('converts out of CZK with the inverse of the quote kept exact', function () {
    expect(Money::ofMinor(251250, 'CZK')->convertThroughCzk('1', 1, 'EUR', '25.125', 1)->minor)->toBe(10000);
});

it('converts between two foreign currencies with both quotes as one rational', function () {
    // 10.00 USD x 23.456 / 25.125 = 9.335721 EUR, i.e. 933.5721 minor units, rounded once.
    $converted = Money::ofMinor(1000, 'USD')->convertThroughCzk('23.456', 1, 'EUR', '25.125', 1);

    expect($converted->minor)->toBe(934)
        ->and($converted->currency)->toBe('EUR');
});

it('converts a currency without fraction digits using a quote for 100 units', function () {
    // 1000 JPY at 15.000 CZK per 100 JPY is 150.00 CZK.
    expect(Money::ofMinor(1000, 'JPY')->convertThroughCzk('15.000', 100, 'CZK', '1', 1)->minor)->toBe(15000);
});

it('refuses invalid quotes and currencies in a conversion', function (string $sourceRate, int $sourceUnit, string $target, string $targetRate, int $targetUnit) {
    Money::ofMinor(1000, 'EUR')->convertThroughCzk($sourceRate, $sourceUnit, $target, $targetRate, $targetUnit);
})->throws(InvalidArgumentException::class)->with([
    'zero source rate' => ['0', 1, 'CZK', '1', 1],
    'negative source rate' => ['-25', 1, 'CZK', '1', 1],
    'zero target rate' => ['25', 1, 'CZK', '0', 1],
    'source unit below one' => ['25', 0, 'CZK', '1', 1],
    'target unit below one' => ['25', 1, 'CZK', '1', 0],
    'unknown target currency' => ['25', 1, 'XXQ', '1', 1],
    'malformed rate' => ['25e1', 1, 'CZK', '1', 1],
]);

it('rounds a converted line amount once (D-09, ruling R-01-1)', function () {
    $unitPrice = Money::ofMinor(150000, 'CZK');

    $lineAmount = $unitPrice->timesThroughCzk('10.0000', '1', 1, 'EUR', '25.125', 1);
    $displayUnitPrice = $unitPrice->convertThroughCzk('1', 1, 'EUR', '25.125', 1);

    // 15000 CZK / 25.125 = 597.014925 EUR, so 59701.4925 minor units rounded once.
    expect($lineAmount->minor)->toBe(59701)
        ->and($displayUnitPrice->minor)->toBe(5970)
        // 5970 x 10 = 59700 is the two-rounding result (unit price rounded, then multiplied) that must not be produced.
        ->and($displayUnitPrice->times('10.0000')->minor)->toBe(59700)
        ->and($lineAmount->minor)->not->toBe($displayUnitPrice->times('10.0000')->minor);
});

it('rounds a negative converted line away from zero so a full credit line cancels the original', function () {
    $unitPrice = Money::ofMinor(150000, 'CZK');

    $original = $unitPrice->timesThroughCzk('10.0000', '1', 1, 'EUR', '25.125', 1);
    $credit = $unitPrice->timesThroughCzk('-10.0000', '1', 1, 'EUR', '25.125', 1);

    expect($credit->minor)->toBe(-59701)
        ->and($original->plus($credit)->isZero())->toBeTrue();
});

it('agrees with convert() then times() when the unit conversion is exact', function () {
    $line = Money::ofMinor(1000, 'EUR')->timesThroughCzk('2.5000', '25.125', 1, 'CZK', '1', 1);

    expect($line->minor)->toBe(62813)
        ->and($line->equals(Money::ofMinor(1000, 'EUR')->convert('25.125', 'CZK', 1)->times('2.5000')))->toBeTrue();
});

it('refuses invalid input for a converted line amount', function (string $quantity, string $sourceCurrency, string $sourceRate, int $sourceUnit, string $target, string $targetRate, int $targetUnit) {
    Money::ofMinor(1000, $sourceCurrency)->timesThroughCzk($quantity, $sourceRate, $sourceUnit, $target, $targetRate, $targetUnit);
})->throws(InvalidArgumentException::class)->with([
    'same currency is priced with times()' => ['2.0000', 'EUR', '25.125', 1, 'EUR', '25.125', 1],
    'malformed quantity' => ['1.23456', 'EUR', '25.125', 1, 'CZK', '1', 1],
    'exponent quantity' => ['1e2', 'EUR', '25.125', 1, 'CZK', '1', 1],
    'zero source rate' => ['2.0000', 'EUR', '0', 1, 'CZK', '1', 1],
    'zero target rate' => ['2.0000', 'EUR', '25.125', 1, 'USD', '0', 1],
    'unit below one' => ['2.0000', 'EUR', '25.125', 0, 'CZK', '1', 1],
]);
