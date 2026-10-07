<?php

declare(strict_types=1);

/**
 * Every string leaf of a translation array, keyed by its path.
 *
 * @param  array<array-key, mixed>  $data
 * @return array<string, string>
 */
function langStrings(array $data, string $path = ''): array
{
    $strings = [];

    foreach ($data as $key => $value) {
        $here = $path === '' ? (string) $key : $path.'.'.$key;

        if (is_array($value)) {
            $strings += langStrings($value, $here);
        } elseif (is_string($value)) {
            // The key counts too: lang/cs.json keys are English sentences, but a decomposed one would never match.
            $strings[$here] = $value;
        }
    }

    return $strings;
}

/**
 * Paths of the strings that are not valid UTF-8 in Unicode NFC form.
 *
 * @param  array<string, string>  $strings
 * @return list<string>
 */
function langEncodingProblems(array $strings): array
{
    $problems = [];

    foreach ($strings as $path => $string) {
        if (! mb_check_encoding($string, 'UTF-8')) {
            $problems[] = $path.' is not valid UTF-8';
        } elseif (! Normalizer::isNormalized($string, Normalizer::FORM_C)) {
            $problems[] = $path.' is not in NFC form';
        }
    }

    return $problems;
}

it('keeps every Czech translation string valid UTF-8 in NFC form', function (): void {
    $strings = [];

    $json = json_decode((string) file_get_contents(lang_path('cs.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($json)->toBeArray();
    $strings += langStrings($json, 'cs.json');

    $files = glob(lang_path('cs/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $strings += langStrings((array) require $file, 'cs/'.basename($file, '.php'));
    }

    expect($strings)->not->toBeEmpty()
        ->and(langEncodingProblems($strings))->toBe([]);
});

it('reports a decomposed string, so the encoding check is not vacuous', function (): void {
    // "ě" written as "e" plus U+030C COMBINING CARON, assembled from fragments.
    $decomposed = 'Zapome'.'n'."\u{0301}".'li jste heslo, ba'."\u{0301}".'se';
    $composed = 'Přihlášení';

    expect(langEncodingProblems(['fixture.decomposed' => $decomposed]))->toBe(['fixture.decomposed is not in NFC form'])
        ->and(langEncodingProblems(['fixture.composed' => $composed]))->toBe([])
        ->and(langEncodingProblems(['fixture.broken' => "\xC3\x28"]))->toBe(['fixture.broken is not valid UTF-8']);
});
