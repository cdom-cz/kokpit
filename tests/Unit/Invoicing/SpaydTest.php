<?php

declare(strict_types=1);

use App\Domain\Invoicing\Pdf\QrPng;
use App\Domain\Invoicing\Pdf\Spayd;
use App\Domain\Shared\Money\Money;
use Tests\Support\FictionalIban;
use Tests\Support\QrDecode;

function spaydPayment(?string $message = null, ?Money $amount = null, ?string $variableSymbol = null, ?string $iban = null, ?string $dueDate = null): string
{
    return Spayd::forPayment(
        $iban ?? FictionalIban::czech(),
        $amount ?? Money::ofMinor(125000, 'CZK'),
        $variableSymbol ?? '20'.'26'.'0007',
        $dueDate ?? '2026-10-30',
        $message ?? 'Faktura 2026-test',
    );
}

it('transliterates the message to upper-case ASCII and replaces an asterisk', function (): void {
    expect(Spayd::sanitiseMessage('Zálohová faktura č. 7*8'))->toBe('ZALOHOVA FAKTURA C. 7 8')
        ->and(Spayd::sanitiseMessage('Příliš žluťoučký kůň úpěl ďábelské ódy'))->toBe('PRILIS ZLUTOUCKY KUN UPEL DABELSKE ODY');
});

it('cuts the message to 60 characters', function (): void {
    $cut = Spayd::sanitiseMessage(str_repeat('abcdefghij', 10));

    expect($cut)->toHaveLength(60)
        ->and($cut)->toBe(str_repeat('ABCDEFGHIJ', 6));
});

it('does not let an injected asterisk add a SPAYD key', function (): void {
    $payload = spaydPayment('Faktura *ACC:'.FictionalIban::czech('34').'*AM:1.00');

    // The header and the five real keys: ACC, AM, CC, X-VS, DT, MSG - one account, one amount.
    expect(substr_count($payload, 'ACC:'))->toBe(2)
        ->and(explode('*', $payload))->toHaveCount(8)
        ->and(substr_count(explode('*MSG:', $payload)[1], '*'))->toBe(0);
});

it('drops the message key when nothing printable is left', function (): void {
    expect(spaydPayment('***'))->not->toContain('MSG:')
        ->and(spaydPayment(''))->not->toContain('MSG:');
});

it('accepts a variable symbol of one to ten digits', function (string $symbol): void {
    expect(spaydPayment(variableSymbol: $symbol))->toContain('*X-VS:'.$symbol.'*');
})->with([
    'one digit' => ['7'],
    'ten digits' => [str_repeat('7', 10)],
]);

it('refuses a variable symbol that is empty, has letters or has eleven digits', function (string $symbol): void {
    spaydPayment(variableSymbol: $symbol);
})->with([
    'empty' => [''],
    'letters' => ['AB12'],
    'eleven digits' => [str_repeat('7', 11)],
    'trailing newline' => ["12\n"],
])->throws(InvalidArgumentException::class);

it('refuses a foreign currency', function (): void {
    spaydPayment(amount: Money::ofMinor(125000, 'EUR'));
})->throws(InvalidArgumentException::class);

it('refuses a Slovak IBAN', function (): void {
    spaydPayment(iban: FictionalIban::of('SK'));
})->throws(InvalidArgumentException::class);

it('refuses a Czech-looking IBAN with a wrong checksum', function (): void {
    $iban = FictionalIban::czech();
    $last = (int) substr($iban, -1);

    spaydPayment(iban: substr($iban, 0, -1).(string) (($last + 1) % 10));
})->throws(InvalidArgumentException::class);

it('accepts an IBAN written with spaces and in lower case', function (): void {
    $iban = FictionalIban::czech();

    expect(spaydPayment(iban: mb_strtolower(trim(chunk_split($iban, 4, ' ')))))->toContain('ACC:'.$iban.'*');
});

it('says whether a payment QR is supported', function (): void {
    $czech = FictionalIban::czech();

    expect(Spayd::supports('CZK', $czech))->toBeTrue()
        ->and(Spayd::supports('EUR', $czech))->toBeFalse()
        ->and(Spayd::supports('CZK', null))->toBeFalse()
        ->and(Spayd::supports('CZK', FictionalIban::of('SK')))->toBeFalse()
        ->and(Spayd::supports('CZK', ''))->toBeFalse();
});

it('writes a small amount with a dot and two decimals', function (): void {
    expect(spaydPayment(amount: Money::ofMinor(5, 'CZK')))->toContain('*AM:0.05*');
});

it('refuses a negative or a zero amount', function (int $minor): void {
    spaydPayment(amount: Money::ofMinor($minor, 'CZK'));
})->with([
    'negative' => [-100],
    'zero' => [0],
])->throws(InvalidArgumentException::class);

it('refuses an amount longer than ten characters', function (): void {
    spaydPayment(amount: Money::ofMinor(1000000000, 'CZK'));
})->throws(InvalidArgumentException::class);

it('refuses a due date that is not a calendar date', function (string $date): void {
    spaydPayment(dueDate: $date);
})->with([
    'not a date' => ['soon'],
    'month 13' => ['2026-13-01'],
    'february 30th' => ['2026-02-30'],
    'compact' => ['2026'.'1030'],
])->throws(InvalidArgumentException::class);

it('renders and decodes the longest allowed payload', function (): void {
    $payload = spaydPayment(
        message: str_repeat('x', 100),
        amount: Money::ofMinor(999999999, 'CZK'),
        variableSymbol: str_repeat('9', 10),
    );

    expect($payload)->toContain('*AM:9999999.99*')
        ->and(QrDecode::payload(QrPng::dataUri($payload)))->toBe($payload);
});
