<?php

declare(strict_types=1);

use App\Domain\Invoicing\Support\InvoiceQuantity;

it('turns exact seconds into hours rounded half-up once to four decimals', function (int $seconds, string $expected) {
    expect(InvoiceQuantity::fromSeconds($seconds)->toDecimal())->toBe($expected);
})->with([
    'a real duration' => [45305, '12.5847'],
    'half an hour' => [1800, '0.5000'],
    'one second rounds up from 0.00028' => [1, '0.0003'],
    'five seconds' => [5, '0.0014'],
    'zero' => [0, '0.0000'],
    'one hour' => [3600, '1.0000'],
]);

it('refuses a negative number of seconds', function () {
    InvoiceQuantity::fromSeconds(-1);
})->throws(InvalidArgumentException::class);

it('refuses a number of seconds that does not fit', function () {
    InvoiceQuantity::fromSeconds(PHP_INT_MAX);
})->throws(OverflowException::class);

it('formats a quantity for a locale with trailing zeros trimmed', function () {
    expect(InvoiceQuantity::fromDecimal('12.5847')->format('cs'))->toBe('12,5847')
        ->and(InvoiceQuantity::fromDecimal('2.5')->format('cs'))->toBe('2,5')
        ->and(InvoiceQuantity::fromDecimal('3')->format('cs'))->toBe('3')
        ->and(InvoiceQuantity::fromDecimal('2.5')->format('en'))->toBe('2.5')
        ->and(InvoiceQuantity::fromDecimal('1.3333')->format('cs'))->toBe('1,3333')
        ->and(InvoiceQuantity::fromDecimal('-2.5')->format('cs'))->toBe('-2,5');
});

it('refuses an unknown locale when formatting', function () {
    InvoiceQuantity::fromDecimal('1')->format('xx');
})->throws(InvalidArgumentException::class);

it('parses a typed quantity with a comma or a point and never rounds', function () {
    expect(InvoiceQuantity::fromDecimal('2,5')->tenThousandths)->toBe(25000)
        ->and(InvoiceQuantity::fromDecimal('2.5')->tenThousandths)->toBe(25000)
        ->and(InvoiceQuantity::fromDecimal('2,5')->equals(InvoiceQuantity::fromDecimal('2.5')))->toBeTrue()
        ->and(InvoiceQuantity::fromDecimal('-0.0001')->tenThousandths)->toBe(-1)
        ->and(InvoiceQuantity::fromDecimal('0')->isZero())->toBeTrue()
        ->and(InvoiceQuantity::fromDecimal('-0.5')->toDecimal())->toBe('-0.5000');
});

it('refuses a malformed quantity', function (string $text) {
    InvoiceQuantity::fromDecimal($text);
})->throws(InvalidArgumentException::class)->with([
    'too many fraction digits' => ['1.23456'],
    'letters' => ['abc'],
    'empty' => [''],
    'exponent' => ['1e3'],
    'grouping space' => ['1 000'],
    'too many integer digits' => ['12345678901'],
    'trailing separator' => ['1.'],
]);

it('adds, subtracts, negates and compares quantities', function () {
    $a = InvoiceQuantity::fromDecimal('1.5');
    $b = InvoiceQuantity::fromDecimal('0.25');

    expect($a->plus($b)->toDecimal())->toBe('1.7500')
        ->and($a->minus($b)->toDecimal())->toBe('1.2500')
        ->and($a->negated()->toDecimal())->toBe('-1.5000')
        ->and($a->isPositive())->toBeTrue()
        ->and($a->negated()->isPositive())->toBeFalse()
        ->and($a->minus($a)->isZero())->toBeTrue()
        ->and($a->compareTo($b))->toBe(1)
        ->and($b->compareTo($a))->toBe(-1)
        ->and($a->compareTo(InvoiceQuantity::fromDecimal('1.5000')))->toBe(0);
});
