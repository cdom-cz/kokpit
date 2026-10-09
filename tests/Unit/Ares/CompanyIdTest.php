<?php

declare(strict_types=1);

use App\Domain\Clients\Ares\CompanyId;
use Tests\Support\FictionalCompanyId;

/*
 * The Czech company number check. Every checksum-valid value is generated at
 * test time; the placeholder 12345678 is checksum-invalid by design (the check
 * digit 9 is expected), so it is the one literal that may appear here.
 */

it('accepts generated numbers with a computed check digit', function (): void {
    foreach (range(1, 100) as $_) {
        $number = FictionalCompanyId::valid();

        expect(CompanyId::isValid($number))->toBeTrue($number);
    }
});

it('refuses the placeholder because its check digit should be 9', function (): void {
    expect(CompanyId::isValid('12345678'))->toBeFalse();
});

it('refuses generated numbers with a wrong check digit', function (): void {
    foreach (range(1, 100) as $_) {
        $number = FictionalCompanyId::invalid();

        expect(CompanyId::isValid($number))->toBeFalse($number);
    }
});

it('needs check digit 1 for remainder 0 and check digit 0 for remainder 1', function (): void {
    $zero = FictionalCompanyId::validWithRemainder(0);
    $one = FictionalCompanyId::validWithRemainder(1);

    expect($zero[7])->toBe('1')
        ->and(CompanyId::isValid($zero))->toBeTrue()
        ->and(CompanyId::isValid(substr($zero, 0, 7).'0'))->toBeFalse()
        ->and($one[7])->toBe('0')
        ->and(CompanyId::isValid($one))->toBeTrue()
        ->and(CompanyId::isValid(substr($one, 0, 7).'1'))->toBeFalse();
});

it('uses 11 minus the remainder for every other remainder', function (int $remainder): void {
    $number = FictionalCompanyId::validWithRemainder($remainder);

    expect((int) $number[7])->toBe(11 - $remainder)
        ->and(CompanyId::isValid($number))->toBeTrue();
})->with(range(2, 10));

it('refuses a number of the wrong length, letters, spaces and the empty string', function (string $text): void {
    expect(CompanyId::isValid($text))->toBeFalse();
})->with([
    'empty' => '',
    'seven digits' => '1234567',
    'nine digits' => '123456789',
    'letters' => 'abcdefgh',
    'letter among digits' => '1234567a',
    'inner space' => '1234 5678',
    'leading space' => ' 12345678',
    'trailing space' => '12345678 ',
    'trailing newline' => "12345678\n",
    'dash' => '1234567-',
    'only spaces' => '        ',
]);

it('refuses a valid number written with a space or a prefix', function (): void {
    $number = FictionalCompanyId::valid();

    expect(CompanyId::isValid(' '.$number))->toBeFalse()
        ->and(CompanyId::isValid($number.' '))->toBeFalse()
        ->and(CompanyId::isValid('CZ'.$number))->toBeFalse()
        ->and(CompanyId::isValid(substr($number, 0, 4).' '.substr($number, 4)))->toBeFalse();
});
