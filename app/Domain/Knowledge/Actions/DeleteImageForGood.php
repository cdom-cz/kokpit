<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

/**
 * Deletes one image for good, at once (IMG-03, D-19): the object, the image row and every reference row of every
 * version go in one transaction, so every reference to it in the body and in every stored version renders as its alt
 * text from then on. The leaked-secret case cannot wait for the cleanup, so this is the Admin's immediate counterpart
 * of the redaction of a version, and like it irreversible by design (the owner settled that, there is no undo).
 *
 * Only a bound image can be deleted here (the Obrázky table lists bound images only; the cleanup removes pending ones).
 * The image of an archived article can be deleted too. A Partner cannot (D-10): the policy is admin-only.
 *
 * The reason is required, validated by {@see ReasonText} and then dropped (owner decision OD-1): it may hold what the
 * Admin pasted. The activity log gets one `image_deleted` row on the article with the actor and the flag that a reason
 * was recorded; the reason text and the file name reach no table, no log line, no exception message and no result.
 *
 * Order inside the transaction (the lock order of SaveArticle, research Pitfall 7): the article row, then the image
 * row, then the reference rows, the image row, the object and the activity row. The object is deleted INSIDE the
 * transaction on purpose: for a leaked secret the object must be gone before the success is reported, and a failed
 * delete rolls the rows back so the image stays complete. Accepted residual: if the commit itself fails after the object
 * went, the row remains without its object, the image route answers 404 and a retry completes the delete.
 *
 * With {@see BindArticleImages} and {@see RedactVersion} this is the third reviewed writer of the reference table; the
 * Arch test pins the list. The reference rows go through the query builder: no model event fires and nothing is logged
 * by the model layer.
 *
 * Failure: a database or storage failure leaves as ArticleWriteFailed with the SQLSTATE (or the fixed code `storage`)
 * only; the original message would carry the bound values.
 */
final class DeleteImageForGood
{
    public const int MAX_REASON_LENGTH = 500;

    /**
     * @param  mixed  $reason  the typed reason; only the fact that one exists is kept
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException a pending image, or an image that no longer exists
     * @throws ValidationException a field error on `reason`
     * @throws ArticleWriteFailed
     */
    public function handle(User $actor, KnowledgeArticleImage $image, mixed $reason): void
    {
        $articleId = $image->article_id;

        if ($articleId === null) {
            throw (new ModelNotFoundException)->setModel(KnowledgeArticleImage::class, [$image->getKey()]);
        }

        Gate::forUser($actor)->authorize('update', $image);

        $article = KnowledgeArticle::query()->withTrashed()->findOrFail($articleId);

        Gate::forUser($actor)->authorize('update', $article);

        ReasonText::length(
            $reason,
            self::MAX_REASON_LENGTH,
            'knowledge.images.delete.errors.reason_required',
            'knowledge.images.delete.errors.reason_too_long',
        );
        $reason = null;

        try {
            DB::transaction(function () use ($actor, $image, $articleId): void {
                $locked = KnowledgeArticle::query()->withTrashed()->whereKey($articleId)->lockForUpdate()->firstOrFail();

                Gate::forUser($actor)->authorize('update', $locked);

                $stored = KnowledgeArticleImage::query()
                    ->whereKey($image->getKey())
                    ->where('article_id', $locked->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                KnowledgeArticleVersionImage::query()->toBase()->where('image_id', $stored->getKey())->delete();

                KnowledgeArticleImage::query()->whereKey($stored->getKey())->toBase()->delete();

                $this->deleteObject($stored->storagePath());

                activity()
                    ->useLog($locked->getMorphClass())
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('image_deleted')
                    ->withProperties(['reason_recorded' => true])
                    ->log('image_deleted');
            });
        } catch (PDOException $e) {
            // The original exception is not chained: its message holds the bound values.
            throw new ArticleWriteFailed((string) $e->getCode());
        }
    }

    /**
     * Removes the object. A refusal of the storage, in any form, rolls the surrounding transaction back through the
     * exception; the object key holds no user text and neither does the exception.
     *
     * @throws ArticleWriteFailed
     */
    private function deleteObject(string $path): void
    {
        try {
            $deleted = Storage::disk()->delete($path);
        } catch (Throwable) {
            throw new ArticleWriteFailed('storage');
        }

        if ($deleted === false) {
            throw new ArticleWriteFailed('storage');
        }
    }
}
