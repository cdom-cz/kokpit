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
