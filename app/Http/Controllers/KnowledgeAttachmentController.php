<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Streams one stored knowledge image to the signed-in Admin (IMG-02, D-10 to D-15). The route sits inside the panel's
 * authentication and has no signature on purpose (D-11): a copied or stored link opens nothing without a live session,
 * and nothing opens after logout. The order of the checks is fixed, so that a foreign image can never be told apart
 * from an unknown one and so that nobody learns that an image exists before they may see it:
 *
 * 1. the user exists and may see the images at all, else 403 (a Partner is refused before any lookup, as the article
 *    screen does),
 * 2. the id has the shape of a UUID (404),
 * 3. the image row is found by its lowercase id (404),
 * 4. a pending image (no article yet) is served only to its uploader, so the editor preview can show it, and is 404
 *    for everybody else (D-13 amendment); a bound image follows the owning article, archived ones included (D-14),
 *    through the policy of the article,
 * 5. a conditional request that carries the stored digest as its ETag gets 304,
 * 6. the object is streamed from the private disk (404 when the object is gone).
 *
 * The 304 comes only after step 4 and never earlier: an ETag answer before the authorisation would tell a caller
 * without access that the image exists (research Pitfall 5). The browser may keep a private copy for up to five
 * minutes after a delete or a logout (accepted by D-12); the server refuses every fresh request.
 *
 * The object is streamed by the application. No bucket URL, presigned or temporary URL and no redirect is ever
 * produced, and nothing about the request or the object is logged. The response is the stored (re-encoded) type, never
 * sniffed, with its own sandbox CSP and without a file name, because names can carry client names (D-12). Only this
 * response gets the CSP; the pages of the panel get none (D-15).
 */
final class KnowledgeAttachmentController
{
    public const string ROUTE = 'filament.admin.knowledge.attachment';

    private const string CACHE_CONTROL = 'private, max-age=300';

    private const string CONTENT_SECURITY_POLICY = "default-src 'none'; sandbox";

    /**
     * The application path of one image, relative to the host. It matches InternalImageSrcSanitizer::PATTERN, which is
     * how the renderer recognises the one internal image address (pinned by a Feature test).
     */
    public static function path(string $id): string
    {
        return route(self::ROUTE, ['attachment' => $id], false);
    }

    public function __invoke(Request $request, string $attachment): SymfonyResponse
    {
        $user = $request->user();

        abort_unless($user !== null && Gate::forUser($user)->allows('viewAny', KnowledgeArticleImage::class), 403);
        abort_unless(Str::isUuid($attachment), 404);

        $image = KnowledgeArticleImage::query()->whereKey(Str::lower($attachment))->first();

        abort_if($image === null, 404);

        if ($image->article_id === null) {
            abort_unless($image->uploaded_by === $user->getKey(), 404);
        } else {
            $article = KnowledgeArticle::query()->withTrashed()->whereKey($image->article_id)->first();

            abort_if($article === null, 404);

            Gate::forUser($user)->authorize('view', $article);
        }

        $headers = [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => self::CACHE_CONTROL,
            'ETag' => '"'.$image->sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Content-Disposition' => 'inline',
            'Vary' => 'Cookie',
        ];

        if ($this->matchesETag($request, $image->sha256)) {
            return new Response('', 304, $headers);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk();
        $path = $image->storagePath();

        // A row without its object is a missing image, not a half-sent response.
        abort_unless($disk->exists($path), 404);

        // Content-Disposition is passed in, so the adapter skips its own file name handling; the explicit Content-Type
        // and Content-Length spare it a metadata request to the bucket.
        return $disk->response($path, null, [...$headers, 'Content-Length' => (string) $image->size_bytes]);
    }

    /**
     * Whether the request names the stored digest in If-None-Match. A weak validator (W/ prefix) compares equal, as the
     * weak comparison of a conditional GET allows.
     */
    private function matchesETag(Request $request, string $sha256): bool
    {
        $stored = '"'.$sha256.'"';

        foreach ($request->getETags() as $candidate) {
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $stored) {
                return true;
            }
        }

        return false;
    }
}
