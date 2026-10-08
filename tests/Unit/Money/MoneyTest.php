<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;

it('builds an amount from minor units and an ISO currency', function () {
    $amount = Money::ofMinor(123456, 'CZK');

    expect($amount->minor)->toBe(123456)
        ->and($amount->currency)->toBe('CZK')
        ->and($amount->isZero())->toBeFalse()
        ->and(Money::zero('EUR')->isZero())->toBeTrue()
        ->and($amount->equals(Money::ofMinor(123456, 'CZK')))->toBeTrue()
        ->and($amount->equals(Money::ofMinor(123456, 'EUR')))->toBeFalse()
        ->and($amount->equals(Money::ofMinor(123457, 'CZK')))->toBeFalse();
});

it('computes 1234.56 CZK per hour for 4020 seconds as 137859 minor units', function () {
    $rate = Money::ofMinor(123456, 'CZK');

    expect(Money::forDuration($rate, 4020)->minor)->toBe(137859);
});

it('rounds 1.5 minor units up to 2', function () {
    expect(Money::forDuration(Money::ofMinor(3, 'CZK'), 1800)->minor)->toBe(2);
});

it('rounds half away from zero at the single rounding point', function (string $exact, int $expected) {
    expect(Money::fromExactMinor($exact, 'CZK')->minor)->toBe($expected);
})->with([
    'half rounds up' => ['0.5', 1],
    'below half rounds down' => ['0.49', 0],
    'negative half rounds away from zero' => ['-0.5', -1],
    'a third rounds down' => ['1/3', 0],
    'two thirds round up' => ['2/3', 1],
    'an exact integer stays' => ['42', 42],
]);

it('rejects text that is not a number at the rounding point', function () {
    Money::fromExactMinor('abc', 'CZK');
})->throws(InvalidArgumentException::class);

it('rounds once per line: three 29 minute parts give 145, rounding each part first gives 144', function () {
    $rate = Money::ofMinor(100, 'CZK');
    $part = [$rate, 1740];

    $perLine = Money::forDurations('CZK', [$part, $part, $part]);
    $perPart = Money::forDuration($rate, 1740)
        ->plus(Money::forDuration($rate, 1740))
        ->plus(Money::forDuration($rate, 1740));

    expect($perLine->minor)->toBe(145)
        ->and($perPart->minor)->toBe(144);
});

it('rounds once over the exact sum of parts with different rates', function () {
    // 100 minor/h for 1800 s = 50, 3 minor/h for 1800 s = 1.5, exact sum 51.5 rounds to 52.
    $line = Money::forDurations('CZK', [
        [Money::ofMinor(100, 'CZK'), 1800],
        [Money::ofMinor(3, 'CZK'), 1800],
    ]);

    expect($line->minor)->toBe(52);
});

it('gives zero for a line without parts', function () {
    expect(Money::forDurations('CZK', [])->isZero())->toBeTrue();
});

it('rejects a duration part in another currency than the line', function () {
    Money::forDurations('CZK', [[Money::ofMinor(100, 'EUR'), 3600]]);
})->throws(InvalidArgumentException::class);

it('rejects a negative duration', function () {
    Money::forDuration(Money::ofMinor(100, 'CZK'), -1);
})->throws(InvalidArgumentException::class);

it('converts 100.00 EUR at 24.4050000000 to 244050 minor CZK', function () {
    $converted = Money::ofMinor(10000, 'EUR')->convert('24.4050000000', 'CZK');

    expect($converted->minor)->toBe(244050)
        ->and($converted->currency)->toBe('CZK');
});

it('converts a JPY amount at a rate quoted per 100 units', function () {
    // 10000 JPY has no fraction digits: 10000 / 100 * 15.12345 = 1512.345 CZK = 151234.5 minor, rounded half up.
    $converted = Money::ofMinor(10000, 'JPY')->convert('15.1234500000', 'CZK', 100);

    expect($converted->minor)->toBe(151235);
});

it('converts from CZK to JPY using the fraction digits of each currency', function () {
    // 1500.00 CZK at 0.1 JPY per CZK is 150 JPY, and JPY has no fraction digits.
    $converted = Money::ofMinor(150000, 'CZK')->convert('0.1000000000', 'JPY');

    expect($converted->minor)->toBe(150)
        ->and($converted->currency)->toBe('JPY');
});

it('rejects a rate with a decimal comma, too many digits or a float', function (string $rate) {
    Money::ofMinor(10000, 'EUR')->convert($rate, 'CZK');
})->with([
    'decimal comma' => ['24,405'],
    'eleven fraction digits' => ['24.40500000001'],
    'exponent' => ['2.44e1'],
    'empty' => [''],
    'spaces' => [' 24.405 '],
])->throws(InvalidArgumentException::class);

it('rejects a float rate', function () {
    Money::ofMinor(10000, 'EUR')->convert(24.405, 'CZK');
})->throws(TypeError::class);

it('rejects a unit amount below one', function () {
    Money::ofMinor(10000, 'EUR')->convert('24.4050000000', 'CZK', 0);
})->throws(InvalidArgumentException::class);

it('rejects conversion to an invalid currency', function () {
    Money::ofMinor(10000, 'EUR')->convert('24.4050000000', 'czk');
})->throws(InvalidArgumentException::class);

it('refuses to add or subtract money in different currencies', function () {
    $czk = Money::ofMinor(100, 'CZK');
    $eur = Money::ofMinor(100, 'EUR');

    expect(fn () => $czk->plus($eur))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $czk->minus($eur))->toThrow(InvalidArgumentException::class);
});

it('adds and subtracts exactly in one currency', function () {
    $sum = Money::ofMinor(150, 'CZK')->plus(Money::ofMinor(275, 'CZK'));
    $difference = Money::ofMinor(150, 'CZK')->minus(Money::ofMinor(275, 'CZK'));

    expect($sum->minor)->toBe(425)
        ->and($difference->minor)->toBe(-125);
});

it('throws instead of wrapping at the integer boundary', function () {
    expect(fn () => Money::ofMinor(PHP_INT_MAX, 'CZK')->plus(Money::ofMinor(1, 'CZK')))
        ->toThrow(OverflowException::class)
        ->and(fn () => Money::ofMinor(PHP_INT_MIN, 'CZK')->minus(Money::ofMinor(1, 'CZK')))
        ->toThrow(OverflowException::class)
        ->and(fn () => Money::fromExactMinor('9223372036854775808', 'CZK'))
        ->toThrow(OverflowException::class);
});

it('rejects a malformed or unknown currency code', function (string $code) {
    Money::ofMinor(100, $code);
})->with([
    'lower case' => ['czk'],
    'unknown code' => ['XXZ'],
    'empty' => [''],
    'too long' => ['CZKK'],
])->throws(InvalidArgumentException::class);

it('formats Czech amounts', function () {
    $plain = fn (string $text): string => str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);

    expect($plain(Money::ofMinor(123450, 'CZK')->format('cs')))->toBe('1 234,50 Kč')
        ->and($plain(Money::ofMinor(1, 'CZK')->format('cs')))->toBe('0,01 Kč');
});

it('serialises to minor units and currency without a float', function () {
    $json = Money::ofMinor(123450, 'CZK')->jsonSerialize();

    expect($json)->toBe(['minor' => 123450, 'currency' => 'CZK'])
        ->and(json_encode(Money::ofMinor(5, 'EUR')))->toBe('{"minor":5,"currency":"EUR"}');
});

it('parses a typed amount in major units into minor units without rounding', function (string $typed, string $currency, int $minor) {
    expect(Money::fromMajor($typed, $currency)->equals(Money::ofMinor($minor, $currency)))->toBeTrue();
})->with([
    'decimal comma' => ['1250,50', 'CZK', 125050],
    'decimal point' => ['1250.50', 'CZK', 125050],
    'one fraction digit is padded' => ['1250.5', 'CZK', 125050],
    'whole amount' => ['3', 'CZK', 300],
    'zero' => ['0', 'EUR', 0],
    'negative whole amount' => ['-3', 'CZK', -300],
    'trailing zero beyond the minor unit stays exact' => ['1.500', 'CZK', 150],
    'surrounding spaces are trimmed' => [' 12,5 ', 'EUR', 1250],
    'currency without fraction' => ['1500', 'JPY', 1500],
]);

it('refuses a typed amount instead of rounding it', function (string $typed, string $currency) {
    Money::fromMajor($typed, $currency);
})->with([
    'excess decimals' => ['12,345', 'CZK'],
    'excess decimals with a point' => ['0.001', 'EUR'],
    'any fraction on a currency without fraction' => ['10.5', 'JPY'],
    'grouping space' => ['1 250', 'CZK'],
    'grouping comma and point' => ['1,250.50', 'CZK'],
    'empty' => ['', 'CZK'],
    'blank' => ['   ', 'CZK'],
    'not a number' => ['abc', 'CZK'],
    'exponent' => ['1e3', 'CZK'],
    'plus sign' => ['+5', 'CZK'],
    'unknown currency' => ['10', 'XYZ'],
    'lower case currency' => ['10', 'czk'],
])->throws(InvalidArgumentException::class);

it('refuses a typed amount that does not fit into an integer of minor units', function () {
    Money::fromMajor('99999999999999999999', 'CZK');
})->throws(OverflowException::class);

it('prints the amount in major units with a point and the currency fraction digits', function (Money $amount, string $expected) {
    expect($amount->toMajor())->toBe($expected);
})->with([
    'two fraction digits' => [fn () => Money::ofMinor(125050, 'CZK'), '1250.50'],
    'negative' => [fn () => Money::ofMinor(-300, 'CZK'), '-3.00'],
    'below one unit' => [fn () => Money::ofMinor(5, 'EUR'), '0.05'],
    'zero' => [fn () => Money::zero('CZK'), '0.00'],
    'currency without fraction' => [fn () => Money::ofMinor(1500, 'JPY'), '1500'],
]);

it('reads back what it prints', function (int $minor, string $currency) {
    $amount = Money::ofMinor($minor, $currency);

    expect(Money::fromMajor($amount->toMajor(), $currency)->equals($amount))->toBeTrue();
})->with([
    [0, 'CZK'],
    [1, 'CZK'],
    [125050, 'CZK'],
    [-98765, 'EUR'],
    [1500, 'JPY'],
]);

it('lists the ISO currency codes sorted and unique', function () {
    $codes = Money::isoCurrencyCodes();

    expect($codes)->toContain('CZK', 'EUR', 'USD')
        ->and($codes)->toBe(array_values(array_unique($codes)))
        ->and($codes)->toBe((function (array $copy): array {
            sort($copy);

            return $copy;
        })($codes));
});

it('knows exactly the upper-case ISO codes', function () {
    expect(Money::isKnownCurrency('CZK'))->toBeTrue()
        ->and(Money::isKnownCurrency('czk'))->toBeFalse()
        ->and(Money::isKnownCurrency('XYZ'))->toBeFalse()
        ->and(Money::isKnownCurrency(''))->toBeFalse()
        ->and(Money::isKnownCurrency('CZKK'))->toBeFalse();
});

it('refuses 12,345 CZK because a third decimal would need rounding', function () {
    Money::fromMajor('12,345', 'CZK');
})->throws(InvalidArgumentException::class, 'more decimals');
