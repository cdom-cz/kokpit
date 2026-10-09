<?php

declare(strict_types=1);

use App\Domain\Settings\Banking\Iban;

/*
 * Fictional IBANs only: each one is composed at runtime from a country and a
 * repeated digit fragment, so no line of this file holds a complete IBAN.
 */

/**
 * A BBAN of the right length for a country, built from a digit fragment.
 */
function ibanTestBban(string $country, string $fragment = '12'): string
{
    $length = Iban::lengthFor($country);
    assert($length !== null);

    return substr(str_repeat($fragment, $length), 0, $length - 4);
}

it('composes a valid 24-character Czech IBAN from a 20-digit BBAN', function (): void {
    $iban = Iban::compose('CZ', ibanTestBban('CZ'));

    expect($iban)->toHaveLength(24)
        ->and(Iban::isValid($iban))->toBeTrue();
});

it('rejects a composed IBAN when one digit is changed', function (): void {
    $iban = Iban::compose('CZ', ibanTestBban('CZ'));
    $last = (int) substr($iban, -1);
    $flipped = substr($iban, 0, -1).(string) (($last + 1) % 10);

    expect(Iban::isValid($flipped))->toBeFalse();
});

it('accepts a composed IBAN written with spaces every four characters and in lower case after normalising', function (): void {
    $iban = Iban::compose('DE', ibanTestBban('DE', '3'));
    $typed = mb_strtolower(trim(chunk_split($iban, 4, ' ')));

    expect($typed)->toContain(' ')
        ->and(Iban::normalise($typed))->toBe($iban)
        ->and(Iban::isValid(Iban::normalise($typed)))->toBeTrue();
});

it('rejects an IBAN of the wrong length', function (): void {
    $iban = Iban::compose('CZ', ibanTestBban('CZ'));

    expect(Iban::isValid(substr($iban, 0, -1)))->toBeFalse()
        ->and(Iban::isValid($iban.'0'))->toBeFalse();
});

it('rejects an IBAN of an unsupported country', function (): void {
    // US has no IBAN; the shape of a CZ IBAN with another country code must fail on the table, not on the checksum alone.
    $iban = Iban::compose('CZ', ibanTestBban('CZ'));

    expect(Iban::isValid('US'.substr($iban, 2)))->toBeFalse()
        ->and(Iban::lengthFor('US'))->toBeNull();
});

it('rejects non-alphanumeric characters and an unnormalised value', function (): void {
    $iban = Iban::compose('CZ', ibanTestBban('CZ'));

    expect(Iban::isValid(substr($iban, 0, 10).'-'.substr($iban, 11)))->toBeFalse()
        ->and(Iban::isValid(chunk_split($iban, 4, ' ')))->toBeFalse()
        ->and(Iban::isValid(mb_strtolower($iban)))->toBeFalse()
        ->and(Iban::isValid(''))->toBeFalse();
});

it('composes a valid IBAN of the table length for every supported country', function (string $country, int $length): void {
    $iban = Iban::compose($country, ibanTestBban($country));

    expect($iban)->toHaveLength($length)
        ->and(substr($iban, 0, 2))->toBe($country)
        ->and(Iban::isValid($iban))->toBeTrue();
})->with(fn (): array => array_map(
    static fn (string $country): array => [$country, Iban::LENGTHS[$country]],
    array_keys(Iban::LENGTHS),
));

it('composes a valid IBAN from an alphanumeric BBAN', function (): void {
    $iban = Iban::compose('GB', 'WXYZ'.str_repeat('1', 14));

    expect(Iban::isValid($iban))->toBeTrue();
});

it('refuses to compose for an unsupported country or a BBAN of the wrong length', function (): void {
    expect(fn () => Iban::compose('US', '1234'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Iban::compose('CZ', '123'))->toThrow(InvalidArgumentException::class);
});

it('lists Czech and Slovak with 24 characters', function (): void {
    expect(Iban::lengthFor('CZ'))->toBe(24)
        ->and(Iban::lengthFor('SK'))->toBe(24);
});

it('agrees with a published documentation example, assembled from fragments', function (): void {
    $example = implode('', ['GB82', 'WEST', '1234', '5698', '7654', '32']);

    expect(Iban::isValid($example))->toBeTrue()
        ->and(Iban::isValid(substr($example, 0, -1).'3'))->toBeFalse()
        ->and(Iban::compose('GB', implode('', ['WEST', '1234', '5698', '7654', '32'])))->toBe($example);
});
