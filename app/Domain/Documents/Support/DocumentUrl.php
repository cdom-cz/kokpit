<?php

declare(strict_types=1);

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The only way a file gets a link (D-10). The link points at the application, never at the bucket, is
 * signed, lives for `kokpit.documents.signed_url_ttl_seconds` and carries the id of the requesting user
 * under the signature, so a copy of it is useless to anybody else.
 *
 * A screen never renders such a link into its HTML: an action builds it at click time and redirects, because
 * a page that stays open longer than the lifetime would otherwise hold a dead link.
 */
final class DocumentUrl
{
    public const string ROUTE = 'filament.admin.files.document';

    public const string MODE_DOWNLOAD = 'download';

    public const string MODE_INLINE = 'inline';

    public static function download(Document $document, User $user): string
    {
        return self::signed($document, $user, self::MODE_DOWNLOAD);
    }

    public static function preview(Document $document, User $user): string
    {
        return self::signed($document, $user, self::MODE_INLINE);
    }

    private static function signed(Document $document, User $user, string $mode): string
    {
        $ttl = max(1, (int) config('kokpit.documents.signed_url_ttl_seconds'));

        return URL::temporarySignedRoute(self::ROUTE, now()->addSeconds($ttl), [
            'document' => $document->getKey(),
            'mode' => $mode,
            'u' => $user->getKey(),
        ]);
    }
}
