<?php

declare(strict_types=1);

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The only way a file gets a link (D-10). The link points at the application, never at the bucket, is
 * signed, lives for `kokpit.documents.signed_url_ttl_seconds` and carries the id of the requesting user
 * under the signature, so a copy of it is useless to anybody else.
 *
 * An action builds the link at click time and redirects for downloads, archives and image previews, because a
 * page that stays open longer than the lifetime would otherwise hold a dead link. The one exception is the
 * inline preview of a non-image file (a PDF): its row renders a target _blank link bound to the viewing user
 * (owner decision "Signed PDF link exception"), guarded by the unchanged route. A page left open past
 * `kokpit.documents.signed_url_ttl_seconds` holds a link that answers 403 until the list renders again.
 */
final class DocumentUrl
{
    public const string ROUTE = 'filament.admin.files.document';

    public const string ARCHIVE_ROUTE = 'filament.admin.files.archive';

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

    /**
     * The link of a finished ZIP archive (D-08, D-10): the same lifetime and the same user binding as a file link.
     */
    public static function archive(DocumentArchive $archive, User $user): string
    {
        $ttl = max(1, (int) config('kokpit.documents.signed_url_ttl_seconds'));

        return URL::temporarySignedRoute(self::ARCHIVE_ROUTE, now()->addSeconds($ttl), [
            'archive' => $archive->getKey(),
            'u' => $user->getKey(),
        ]);
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
