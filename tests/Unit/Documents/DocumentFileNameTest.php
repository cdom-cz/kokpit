<?php

declare(strict_types=1);

use App\Domain\Documents\Support\DocumentFileName;

/*
 * Display names of uploaded files (D-06): the client's file name is untrusted text. Hostile characters are
 * assembled from code points at runtime, so no test line carries a literal control or bidirectional character.
 */

it('removes control characters, bidirectional overrides and path separators from a display name', function (): void {
    $hostile = implode('', ['report', mb_chr(0x202E), mb_chr(7), '/..', '\\', 'final', mb_chr(0x2066), '.txt']);

    $name = DocumentFileName::displayName($hostile);

    expect($name)->toBe('report-..-final.txt')
        ->and(preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\/\\\\]/u', $name))->toBe(0);
});

it('keeps a Czech word and turns it into Unicode NFC', function (): void {
    $decomposed = 'Zpra'."\u{0301}".'va.pdf';

    $name = DocumentFileName::displayName($decomposed);

    expect($name)->toBe('Zpráva.pdf')
        ->and(Normalizer::isNormalized($name, Normalizer::FORM_C))->toBeTrue();
});

it('strips leading dots and spaces', function (): void {
    expect(DocumentFileName::displayName('  ..hidden.txt'))->toBe('hidden.txt');
});

it('falls back to a neutral name when nothing usable is left', function (): void {
    $controls = implode('', [mb_chr(1), mb_chr(2), mb_chr(0x202E), mb_chr(0x2069)]);

    expect(DocumentFileName::displayName('...'))->toBe(DocumentFileName::FALLBACK)
        ->and(DocumentFileName::displayName($controls))->toBe(DocumentFileName::FALLBACK)
        ->and(DocumentFileName::displayName('   '))->toBe(DocumentFileName::FALLBACK)
        ->and(DocumentFileName::FALLBACK)->toBe('soubor');
});

it('cuts a long name to 255 characters at a character boundary', function (): void {
    $name = DocumentFileName::displayName(str_repeat('č', 300).'.txt');

    expect(mb_strlen($name))->toBe(DocumentFileName::MAX_LENGTH)
        ->and(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
        ->and(DocumentFileName::MAX_LENGTH)->toBe(255);
});

it('takes the lower-case last segment as the extension when it is a plain token', function (): void {
    expect(DocumentFileName::extension('Archive.TAR.GZ'))->toBe('gz')
        ->and(DocumentFileName::extension('smlouva.pdf'))->toBe('pdf')
        ->and(DocumentFileName::extension('noextension'))->toBeNull()
        ->and(DocumentFileName::extension('weird.'.str_repeat('a', 11)))->toBeNull()
        ->and(DocumentFileName::extension('odd.ex-t'))->toBeNull()
        ->and(DocumentFileName::extension('.hidden'))->toBeNull();
});
