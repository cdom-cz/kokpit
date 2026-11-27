<?php

declare(strict_types=1);

use App\Domain\Knowledge\Images\ImageFormat;
use App\Domain\Knowledge\Images\ImageNames;

/*
 * This file pins how the file name of an upload becomes the default alt text and the stored name (D-03, R-20-05-4,
 * research Pitfall 10). The name is attacker-controlled; every name here is fictional.
 */

it('takes the file name without its last extension as the alt text', function (): void {
    expect(ImageNames::altText('Example photo.JPG'))->toBe('Example photo')
        ->and(ImageNames::altText('screenshot-example.png'))->toBe('screenshot-example')
        ->and(ImageNames::altText('archive.example.png'))->toBe('archive.example')
        ->and(ImageNames::altText('noextension'))->toBe('noextension');
});

it('uses the base name only', function (): void {
    expect(ImageNames::altText('/Users/example/Pictures/photo-example.jpg'))->toBe('photo-example');
});

it('removes every character that could break the Markdown reference', function (): void {
    $alt = ImageNames::altText('a](x)[b\\c.png');

    expect($alt)->toBe('axbc')
        ->and($alt)->not->toContain('[')
        ->and($alt)->not->toContain(']')
        ->and($alt)->not->toContain('(')
        ->and($alt)->not->toContain(')')
        ->and($alt)->not->toContain('\\');
});

it('removes control, bidi and zero-width characters and turns a line break into a space', function (): void {
    $alt = ImageNames::altText("first\u{202E}\nsecond\u{200B}third\u{0007}.png");

    expect($alt)->toBe('first secondthird')
        ->and(preg_match('/[\p{Cc}\p{Cf}]/u', $alt))->toBe(0);
});

it('cuts the alt text at 120 characters', function (): void {
    expect(mb_strlen(ImageNames::altText(str_repeat('a', 300).'.png')))->toBe(120)
        ->and(mb_strlen(ImageNames::altText(str_repeat('ž', 300).'.png')))->toBe(120);
});

it('collapses whitespace and returns an empty alt text for a name that is only an extension', function (): void {
    expect(ImageNames::altText("  two   spaces\there.png"))->toBe('two spaces here')
        ->and(ImageNames::altText('.png'))->toBe('')
        ->and(ImageNames::altText(''))->toBe('');
});

it('normalises the alt text to NFC', function (): void {
    if (! class_exists(Normalizer::class)) {
        $this->markTestSkipped('The intl extension is not loaded.');
    }

    // "e" plus a combining acute accent becomes one precomposed character.
    expect(ImageNames::altText("cafe\u{0301}.png"))->toBe("caf\u{00E9}");
});

it('survives a name that is not valid UTF-8', function (): void {
    expect(ImageNames::altText("bad\xFFname.png"))->toBe('bad?name');
});

it('keeps the base name as the stored file name and drops control and bidi characters', function (): void {
    expect(ImageNames::fileName('photo-example.jpg', ImageFormat::Jpeg))->toBe('photo-example.jpg')
        ->and(ImageNames::fileName("/tmp/dir/ph\u{202E}oto\n-example.jpg", ImageFormat::Jpeg))->toBe('photo-example.jpg');
});

it('names an empty stored file name after the format', function (): void {
    expect(ImageNames::fileName('', ImageFormat::Png))->toBe('image.png')
        ->and(ImageNames::fileName("\u{202E}\n", ImageFormat::Jpeg))->toBe('image.jpg')
        ->and(ImageNames::fileName('   ', ImageFormat::Webp))->toBe('image.webp')
        ->and(ImageNames::fileName('dir/', ImageFormat::Gif))->toBe('image.gif');
});

it('cuts the stored file name at 255 characters', function (): void {
    expect(mb_strlen(ImageNames::fileName(str_repeat('a', 300).'.png', ImageFormat::Png)))->toBe(255);
});
