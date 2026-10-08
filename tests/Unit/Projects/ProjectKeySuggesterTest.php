<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectKeySuggester;

/*
 * The key suggestion from a project name (D-14, PR-02), without the application.
 * Names are fictional; non-Latin names are assembled from code points so the file
 * stays plain ASCII-safe to review.
 */

/**
 * The suggestion for a name when the given keys are taken.
 *
 * @param  list<string>  $taken
 */
function suggestedKey(string $name, array $taken = []): ?string
{
    return ProjectKeySuggester::suggest($name, static fn (string $key): bool => in_array($key, $taken, true));
}

it('suggests the initials of the words of a name', function (string $name, string $expected): void {
    expect(suggestedKey($name))->toBe($expected);
})->with([
    'two words' => ['Nový web', 'NW'],
    'single-letter word dropped when two longer words remain' => ['Správa serverů a sítí', 'SSS'],
    'single-letter word kept when fewer than two longer words remain' => ['Web X', 'WX'],
    'digits are not letters' => ['Web 2 Print', 'WP'],
    'punctuation separates words' => ['e-shop/redesign', 'SR'],
]);

it('suggests the first four letters of a single word, transliterated before they are taken', function (string $name, string $expected): void {
    expect(suggestedKey($name))->toBe($expected);
})->with([
    'diacritics' => ['Účetnictví', 'UCET'],
    'longer word' => ['Eshop', 'ESHO'],
    'short word is kept whole' => ['Web', 'WEB'],
    'two letters' => ['Ab', 'AB'],
    'lower case' => ['intranet', 'INTR'],
]);

it('gives ASCII initials for a name of Czech words', function (): void {
    expect(suggestedKey('Řízení žádostí čtenářů'))->toBe('RZC')
        ->and(suggestedKey('Ředitelství škol'))->toBe('RS');
});

it('falls back to PRJ when fewer than two usable letters remain', function (string $name): void {
    expect(suggestedKey($name))->toBe('PRJ');
})->with([
    'empty' => [''],
    'digits only' => ['123'],
    'punctuation only' => ['!!!'],
    'one letter' => ['X'],
    'one letter between digits' => ['1 X 2'],
    'only non-Latin symbols' => [mb_chr(0x2605).mb_chr(0x2606).mb_chr(0x273F)],
]);

it('takes at most six initials of a name of many words', function (): void {
    $key = suggestedKey('alfa beta gama delta epsilon zeta eta theta');

    expect($key)->toBe('ABGDEZ')
        ->and(strlen((string) $key))->toBe(6);
});

it('walks a fixed order of variants when the initials are taken', function (): void {
    $name = 'Nový web';

    expect(suggestedKey($name, ['NW']))->toBe('NOW')
        ->and(suggestedKey($name, ['NW', 'NOW']))->toBe('NOVW')
        ->and(suggestedKey($name, ['NW', 'NOW', 'NOVW']))->toBe('NOVYW')
        ->and(suggestedKey($name, ['NW', 'NOW', 'NOVW', 'NOVYW']))->toBe('NWE')
        ->and(suggestedKey($name, ['NW', 'NOW', 'NOVW', 'NOVYW', 'NWE']))->toBe('NWEB')
        ->and(suggestedKey($name, ['NW', 'NOW', 'NOVW', 'NOVYW', 'NWE', 'NWEB']))->toBe('NWA')
        ->and(suggestedKey($name, ['NW', 'NOW', 'NOVW', 'NOVYW', 'NWE', 'NWEB', 'NWA']))->toBe('NWB');
});

it('walks the variants of a single word', function (): void {
    $name = 'Účetnictví';

    expect(suggestedKey($name, ['UCET']))->toBe('UCE')
        ->and(suggestedKey($name, ['UCET', 'UCE']))->toBe('UCETN')
        ->and(suggestedKey($name, ['UCET', 'UCE', 'UCETN']))->toBe('UCETNI')
        ->and(suggestedKey($name, ['UCET', 'UCE', 'UCETN', 'UCETNI']))->toBe('UCETA')
        ->and(suggestedKey($name, ['UCET', 'UCE', 'UCETN', 'UCETNI', 'UCETA']))->toBe('UCETB');
});

it('does not repeat a candidate or leave the letters A to Z', function (): void {
    $seen = [];

    $key = ProjectKeySuggester::suggest('Web', static function (string $candidate) use (&$seen): bool {
        $seen[] = $candidate;

        return true;
    });

    expect($key)->toBeNull()
        ->and($seen)->toBe(array_values(array_unique($seen)))
        ->and($seen[0])->toBe('WEB')
        ->and(count($seen))->toBe(27)
        ->and(array_filter($seen, static fn (string $candidate): bool => preg_match('/^[A-Z]{2,6}$/D', $candidate) !== 1))->toBe([]);
});

it('walks PRJ and its variants for a name without letters', function (): void {
    expect(suggestedKey('', ['PRJ']))->toBe('PRJA')
        ->and(suggestedKey('', ['PRJ', 'PRJA']))->toBe('PRJB');
});

it('returns the same suggestion for the same name and the same taken keys', function (): void {
    $taken = ['NW', 'NOW'];

    expect(suggestedKey('Nový web', $taken))->toBe(suggestedKey('Nový web', $taken))
        ->and(suggestedKey('Nový web', $taken))->toBe('NOVW');
});

it('returns null when every candidate is taken', function (): void {
    expect(ProjectKeySuggester::suggest('Nový web', static fn (): bool => true))->toBeNull()
        ->and(ProjectKeySuggester::suggest('', static fn (): bool => true))->toBeNull();
});

it('always suggests two to six capital letters', function (string $name): void {
    $key = suggestedKey($name);

    expect($key)->not->toBeNull()
        ->and(preg_match('/^[A-Z]{2,6}$/D', (string) $key))->toBe(1);
})->with([
    'czech' => ['Příliš žluťoučký kůň úpěl ďábelské ódy'],
    'german' => ['Straße der Äpfel'],
    'mixed' => ['Q3 2026 – Kampaň'],
    'long single word' => ['Pneumonoultramicroscopicsilicovolcanoconiosis'],
    'emoji and letters' => ['Launch '.mb_chr(0x1F680).' day'],
    'whitespace' => ["  \t\n "],
]);
