<?php

declare(strict_types=1);

use App\Domain\Knowledge\Support\ImageReferences;

/*
 * This file pins the one parser and builder of the internal image reference (D-03, R-20-04-1). Unit tests run without
 * the application. Every uuid is fictional.
 */

/**
 * A fictional uuid, distinct per number.
 */
function imageReferencesId(int $number = 1): string
{
    return '0190f3a0-0000-7000-8000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
}

it('finds the uuids of image references, link references and references inside code blocks', function (): void {
    $markdown = '![Example alt](kokpit-attachment:'.imageReferencesId(1).")\n\n"
        .'[Example link](kokpit-attachment:'.imageReferencesId(2).")\n\n"
        ."```\n![Example alt](kokpit-attachment:".imageReferencesId(3).")\n```\n";

    expect(ImageReferences::in($markdown))->toBe([imageReferencesId(1), imageReferencesId(2), imageReferencesId(3)]);
});

it('lowercases the uuids, drops duplicates and keeps the order of first occurrence', function (): void {
    $markdown = 'kokpit-attachment:'.imageReferencesId(2).' kokpit-attachment:'.strtoupper(imageReferencesId(1))
        .' kokpit-attachment:'.imageReferencesId(2).' kokpit-attachment:'.imageReferencesId(1);

    expect(ImageReferences::in($markdown))->toBe([imageReferencesId(2), imageReferencesId(1)]);
});

it('ignores a malformed uuid and text without a reference', function (string $markdown): void {
    expect(ImageReferences::in($markdown))->toBe([]);
})->with([
    'too short' => ['![Example alt](kokpit-attachment:0190f3a0-0000-7000-8000-00000000000)'],
    'not hex' => ['![Example alt](kokpit-attachment:0190f3a0-0000-7000-8000-00000000000g)'],
    'too long' => ['![Example alt](kokpit-attachment:0190f3a0-0000-7000-8000-0000000000011)'],
    'another scheme' => ['![Example alt](other-attachment:0190f3a0-0000-7000-8000-000000000001)'],
    'plain text' => ['Example text without any reference'],
    'empty' => [''],
]);

it('accepts only the exact scheme plus a uuid as an image url', function (string $url, ?string $expected): void {
    expect(ImageReferences::idFromUrl($url))->toBe($expected);
})->with([
    'lowercase' => ['kokpit-attachment:0190f3a0-0000-7000-8000-000000000001', '0190f3a0-0000-7000-8000-000000000001'],
    'uppercase hex' => ['kokpit-attachment:0190F3A0-0000-7000-8000-000000000001', '0190f3a0-0000-7000-8000-000000000001'],
    'a prefix' => ['x-kokpit-attachment:0190f3a0-0000-7000-8000-000000000001', null],
    'a suffix' => ['kokpit-attachment:0190f3a0-0000-7000-8000-000000000001/x', null],
    'a trailing newline' => ["kokpit-attachment:0190f3a0-0000-7000-8000-000000000001\n", null],
    'a space' => [' kokpit-attachment:0190f3a0-0000-7000-8000-000000000001', null],
    'a malformed uuid' => ['kokpit-attachment:0190f3a0-0000-7000-8000', null],
    'an external url' => ['https://example.com/a.png', null],
    'empty' => ['', null],
]);

it('builds the markdown of an image reference', function (): void {
    expect(ImageReferences::markdown(imageReferencesId(), 'Example alt'))
        ->toBe('![Example alt](kokpit-attachment:'.imageReferencesId().')')
        ->and(ImageReferences::SCHEME)->toBe('kokpit-attachment:');
});

it('parses what it builds', function (): void {
    $markdown = ImageReferences::markdown(imageReferencesId(4), '');

    expect(ImageReferences::in($markdown))->toBe([imageReferencesId(4)]);
});
