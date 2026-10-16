<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentDisposition;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one stored file to the signed-in user (DO-03, D-10). The route is signed, short-lived and inside
 * the panel's authentication, so by the time this runs the link is genuine. The order of the checks is
 * fixed so that a foreign file can never be told apart from an unknown one:
 *
 * 1. the user id under the signature is the signed-in user (403),
 * 2. the id has the shape of a UUID (404),
 * 3. the document is found through the Partner-scoped query (404),
 * 4. the policy allows `view` (403),
 * 5. the media row is read as system, because Media is closed to Partners (404 when missing).
 *
 * The object is streamed by the application. No bucket URL, presigned URL or storage path ever reaches the
 * browser, and nothing about the request or the object is logged. An uploaded file is untrusted content:
 * only the inline allowlist of DocumentDisposition is rendered by the browser, everything else is an
 * attachment of type application/octet-stream, and `nosniff` stops the browser from guessing otherwise.
 */
final class DocumentFileController
{
    public function __invoke(Request $request, PartnerContext $context, string $document): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user !== null && $request->query('u') === $user->getKey(), 403);
        abort_unless(Str::isUuid($document), 404);

        $record = Document::query()->whereKey($document)->first();

        abort_if($record === null, 404);

        Gate::authorize('view', $record);

        $media = $context->runAsSystem(static fn (): ?Media => Media::query()
            ->where('model_type', 'document')
            ->where('model_id', $record->getKey())
            ->first());

        abort_if($media === null, 404);

        $inline = $request->query('mode') === DocumentUrl::MODE_INLINE && DocumentDisposition::isInline($record);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        // A row without its object is a missing file, not a half-sent response.
        abort_unless($disk->exists($path), 404);

        // Content-Disposition is passed in, so the adapter skips its own filename handling; the explicit
        // Content-Type and Content-Length spare it a metadata request to the bucket.
        return $disk->response($path, null, [
            'Content-Type' => $inline ? $record->mime_type : DocumentDisposition::DOWNLOAD_MIME_TYPE,
            'Content-Length' => (string) $record->size_bytes,
            'Content-Disposition' => DocumentDisposition::header($inline ? 'inline' : 'attachment', DocumentDisposition::downloadName($record)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
