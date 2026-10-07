<?php

declare(strict_types=1);

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar rendered locally from the user's initials as an inline SVG data URI.
 *
 * Filament's default provider asks a third-party host for the image and would
 * send the user's name to it on every page view.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = $record->getAttribute('name');
        $initials = '';

        foreach (preg_split('/\s+/u', trim(is_string($name) ? $name : '')) ?: [] as $segment) {
            // Skip leading punctuation so "[SYSTEM] Admin" does not start with a bracket.
            $letters = preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment) ?? '';

            if ($letters !== '' && mb_strlen($initials) < 2) {
                $initials .= mb_strtoupper(mb_substr($letters, 0, 1));
            }
        }

        $label = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#52525b"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="sans-serif" font-size="26" font-weight="600">'.$label.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
