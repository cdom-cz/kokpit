<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\RateFormat;

/*
 * The display rule of a CNB rate (UI-SPEC Typography): a decimal comma, three to ten decimals, the trailing zeros
 * beyond the third trimmed, never rounded. The currency names come from ICU in Czech. Every value is invented.
 */

describe('display', function (): void {
    it('trims the trailing zeros beyond the third decimal', function (string $stored, string $shown): void {
        expect(RateFormat::display($stored))->toBe($shown);
    })->with([
        'three significant decimals' => ['25.1250000000', '25,125'],
        'trailing zeros of a longer value' => ['6.4810000000', '6,481'],
        'one significant decimal keeps three' => ['24.3000000000', '24,300'],
        'a whole rate keeps three decimals' => ['1.0000000000', '1,000'],
        'ten significant decimals stay' => ['0.0123456789', '0,0123456789'],
        'four decimals keep the fourth' => ['0.0001000000', '0,0001'],
        'a value without a fraction' => ['25', '25,000'],
        'a short fraction' => ['22.5', '22,500'],
    ]);

    it('never rounds a digit away', function (): void {
        // Rounding to three decimals would give 25,126; to ten the last digit would change.
        expect(RateFormat::display('25.1259999999'))->toBe('25,1259999999')
            ->and(RateFormat::display('0.0000000001'))->toBe('0,0000000001');
    });

    it('refuses a value that is not a plain decimal', function (string $bad): void {
        expect(static fn (): string => RateFormat::display($bad))->toThrow(InvalidArgumentException::class);
    })->with(['', 'abc', '1,5', '1.0E-5', ' 1.5', '1.']);
});

describe('currencyName', function (): void {
    it('gives the Czech name of a currency', function (string $code, string $name): void {
        expect(RateFormat::currencyName($code))->toBe($name);
    })->with([
        ['EUR', 'euro'],
        ['HUF', 'maďarský forint'],
        ['USD', 'americký dolar'],
    ]);

    it('gives null for a code without a name', function (string $code): void {
        expect(RateFormat::currencyName($code))->toBeNull();
    })->with(['ZZZ', 'eur', 'EU', '', 'EUR1']);
});
