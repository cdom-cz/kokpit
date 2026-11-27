<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Queries;

use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Support\ImageContext;
use App\Domain\Knowledge\Support\ImageReferences;
use App\Http\Controllers\KnowledgeAttachmentController;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the per-render image context of a knowledge body (IMG-01, IMG-02, D-13, D-14, R-20-08-1): which images the one
 * renderer may show in this render, with the route path and the stored size of each.
 *
 * The context is built from the database in one query, outside the renderer, so the renderer stays free of the
 * application. The lookup is constrained to the rendered article (`article_id`), so a reference to an image of another
 * article never becomes an image. Only the editor preview passes `$pendingOwnerId`: it adds the images the actor
 * uploaded and no save has bound yet (`article_id` IS NULL), which is how the preview of an unsaved article shows an
 * upload (the D-13 amendment). Every other screen passes the article id alone, so a pending image never shows there.
 *
 * Every src comes from {@see KnowledgeAttachmentController::path()}: no bucket URL, presigned URL or temporary upload
 * path can reach a page through this class.
 */
final class ArticleImages
{
    /**
     * @param  string  $body  the Markdown that is about to be rendered
     * @param  string|null  $articleId  the article the body belongs to; null for a body without an article yet
     * @param  string|null  $pendingOwnerId  the user whose pending images may show too (the editor preview only)
     */
    public function forBody(string $body, ?string $articleId, ?string $pendingOwnerId = null): ImageContext
    {
        $ids = ImageReferences::in($body);

        if ($ids === [] || ($articleId === null && $pendingOwnerId === null)) {
            return ImageContext::none();
        }

        $rows = KnowledgeArticleImage::query()
            ->whereIn('id', $ids)
            ->where(static function (Builder $scope) use ($articleId, $pendingOwnerId): void {
                if ($articleId !== null) {
                    $scope->orWhere('article_id', $articleId);
                }

                if ($pendingOwnerId !== null) {
                    $scope->orWhere(static function (Builder $pending) use ($pendingOwnerId): void {
                        $pending->whereNull('article_id')->where('uploaded_by', $pendingOwnerId);
                    });
                }
            })
            ->get(['id', 'width', 'height']);

        $images = [];

        foreach ($rows as $row) {
            $id = (string) $row->getKey();

            $images[$id] = [
                'src' => KnowledgeAttachmentController::path($id),
                'width' => $row->width,
                'height' => $row->height,
            ];
        }

        return ImageContext::of($images);
    }
}
