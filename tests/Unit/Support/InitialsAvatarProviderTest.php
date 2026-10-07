<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Support\InitialsAvatarProvider;

function avatarSvg(string $name): string
{
    $uri = (new InitialsAvatarProvider)->get(new User(['name' => $name]));

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    return (string) base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
}

it('renders the initials locally as an inline image without any host', function (): void {
    $svg = avatarSvg('Jane Example');

    expect($svg)->toContain('>JE</text>')
        ->and($svg)->not->toMatch('~(?:href|src)=~');
});

it('keeps Czech initials and ignores leading punctuation', function (): void {
    expect(avatarSvg('řehoř Šimek'))->toContain('>ŘŠ</text>')
        ->and(avatarSvg('[SYSTEM] Admin'))->toContain('>SA</text>');
});

it('escapes markup in a name', function (): void {
    expect(avatarSvg('<b>x</b> & y'))->not->toContain('<b>');
});

it('copes with an empty name', function (): void {
    expect(avatarSvg(''))->toContain('<text');
});
