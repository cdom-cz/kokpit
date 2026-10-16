<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Support\DocumentDisposition;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one finished ZIP archive to the Admin who asked for it (DO-02, DO-03, D-08, D-10). The twin of
 * DocumentFileController. The route is signed, short-lived and inside the panel's authentication, so by the time
 * this runs the link is genuine. The order of the checks is fixed so that a foreign, unfinished or expired
 * archive can never be told apart from an unknown one:
 *
 * 1. the user id under the signature is the signed-in user (403),
 * 2. the id has the shape of a UUID (404),
 * 3. the archive is found through the owner scope, which shows a Partner and a second Admin nothing (404),
 * 4. the policy allows `view` (403),
 * 5. the archive is finished, has a path and is not past its expiry (404),
 * 6. the object is on the disk (404).
 *
 * The expiry is checked on every request, not only when the link was made, so an archive is never served after its
 * retention even through a link that is still valid. The path comes from the record only (the database pins its
 * shape), no request value reaches the disk. The ZIP is streamed by the application: no bucket URL, presigned URL
 * or storage path reaches the browser, and the name offered is the server-built `soubory-YYYY-MM-DD.zip`.
 */
final class DocumentArchiveFileController
{
    /** The content type of every archive; the file is an attachment, never rendered by the browser. */
    private const string MIME_TYPE = 'application/zip';

    public function __invoke(Request $request, string $archive): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $request->query('u') === $user->getKey(), 403);
        abort_unless(Str::isUuid($archive), 404);

        $record = DocumentArchive::query()->whereKey($archive)->first();

        abort_if($record === null, 404);

        Gate::authorize('view', $record);

        abort_unless($record->isDownloadable(CarbonImmutable::now()), 404);

        $path = (string) $record->path;

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk();

        // A row without its object is a missing file, not a half-sent response.
        abort_unless($disk->exists($path), 404);

        // Content-Disposition is passed in, so the adapter skips its own filename handling; the explicit
        // Content-Type and Content-Length spare it a metadata request to the bucket.
        return $disk->response($path, null, [
            'Content-Type' => self::MIME_TYPE,
            'Content-Length' => (string) $disk->size($path),
            'Content-Disposition' => DocumentDisposition::header('attachment', $record->downloadFileName()),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
