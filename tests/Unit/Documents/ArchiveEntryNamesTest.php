<?php

declare(strict_types=1);

use App\Domain\Documents\Support\ArchiveEntryNames;

/*
 * Entry names of the ZIP archive (DO-02, research Pitfall 12, T-09-61): a document name becomes a path when the
 * archive is unpacked. Hostile characters are assembled from code points at runtime, so no test line carries a
 * literal control or bidirectional character.
 */

/**
 * Whether a name holds a path separator, a parent-directory segment, a control or a bidirectional character.
 */
function entryNameIsHostile(string $name): bool
{
    return str_contains($name, '/')
        || str_contains($name, '\\')
        || str_contains($name, '..')
        || preg_match('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $name) === 1;
}

describe('safe()', function (): void {
    it('leaves no path separator and no parent-directory segment of a traversal name', function (): void {
        $name = ArchiveEntryNames::safe(implode('/', ['..', '..', 'etc', 'passwd']), null);

        expect(entryNameIsHostile($name))->toBeFalse()
            ->and($name)->not->toStartWith('.');
    });

    it('removes backslashes, drive-like paths and absolute starts', function (string $original): void {
        expect(entryNameIsHostile(ArchiveEntryNames::safe($original, 'txt')))->toBeFalse();
    })->with([
        'windows traversal' => ['..\\..\\windows\\system.ini'],
        'absolute' => ['/etc/shadow'],
        'drive' => ['C:\\boot.ini'],
        'nested dots' => ['a/../../b'],
        'many dots' => ['......'],
    ]);

    it('does not start with a dot', function (): void {
        expect(ArchiveEntryNames::safe('.hidden', 'txt'))->toBe('hidden.txt');
    });

    it('cuts a long name to 150 characters before the extension and keeps the extension', function (): void {
        $name = ArchiveEntryNames::safe(str_repeat('a', 300), 'pdf');

        expect($name)->toBe(str_repeat('a', 150).'.pdf');
    });

    it('cuts multibyte characters by characters, not bytes', function (): void {
        $name = ArchiveEntryNames::safe(str_repeat("\u{0159}", 300).'.pdf', 'pdf');

        expect(mb_strlen($name))->toBe(154)
            ->and($name)->toEndWith('.pdf');
    });

    it('removes controls and bidirectional overrides', function (): void {
        $hostile = implode('', ['rep', mb_chr(0x202E), 'ort', mb_chr(7), mb_chr(0), 'fdp.exe']);

        $name = ArchiveEntryNames::safe($hostile, 'exe');

        expect($name)->toBe('reportfdp.exe')
            ->and(entryNameIsHostile($name))->toBeFalse();
    });

    it('does not double an extension that the name already carries', function (): void {
        expect(ArchiveEntryNames::safe('Faktura.pdf', 'pdf'))->toBe('Faktura.pdf')
            ->and(ArchiveEntryNames::safe('Faktura.PDF', 'pdf'))->toBe('Faktura.pdf');
    });

    it('takes the extension from the name when none is given and keeps it when the stem is cut', function (): void {
        expect(ArchiveEntryNames::safe('Smlouva.pdf', null))->toBe('Smlouva.pdf')
            ->and(ArchiveEntryNames::safe(str_repeat('b', 200).'.docx', null))->toBe(str_repeat('b', 150).'.docx');
    });

    it('turns Unicode into NFC', function (): void {
        $name = ArchiveEntryNames::safe('Zpra'."\u{0301}".'va.pdf', 'pdf');

        expect($name)->toBe('Zpráva.pdf')
            ->and(Normalizer::isNormalized($name, Normalizer::FORM_C))->toBeTrue();
    });

    it('falls back to a fixed word when nothing usable is left', function (): void {
        expect(ArchiveEntryNames::safe('...', null))->toBe('soubor')
            ->and(ArchiveEntryNames::safe('', 'pdf'))->toBe('soubor.pdf')
            ->and(ArchiveEntryNames::safe(' . ', null))->toBe('soubor');
    });

    it('drops an extension that is not a plain token', function (): void {
        expect(ArchiveEntryNames::safe('note', 'p/df'))->toBe('note');
    });
});

describe('unique()', function (): void {
    it('numbers duplicates case-insensitively and keeps the case of each name', function (): void {
        $names = new ArchiveEntryNames;

        expect($names->unique('Smlouva.pdf'))->toBe('Smlouva.pdf')
            ->and($names->unique('smlouva.pdf'))->toBe('smlouva (2).pdf')
            ->and($names->unique('Smlouva.pdf'))->toBe('Smlouva (3).pdf');
    });

    it('leaves different names alone and numbers a name without an extension before nothing', function (): void {
        $names = new ArchiveEntryNames;

        expect($names->unique('a.txt'))->toBe('a.txt')
            ->and($names->unique('b.txt'))->toBe('b.txt')
            ->and($names->unique('readme'))->toBe('readme')
            ->and($names->unique('README'))->toBe('README (2)');
    });

    it('does not collide with a name that already looks like a numbered one', function (): void {
        $names = new ArchiveEntryNames;

        expect($names->unique('x (2).txt'))->toBe('x (2).txt')
            ->and($names->unique('x.txt'))->toBe('x.txt')
            ->and($names->unique('x.txt'))->toBe('x (3).txt')
            ->and($names->unique('x.txt'))->toBe('x (4).txt');
    });

    it('is independent between instances', function (): void {
        $first = new ArchiveEntryNames;
        $second = new ArchiveEntryNames;

        $first->unique('same.txt');

        expect($second->unique('same.txt'))->toBe('same.txt');
    });
});
