<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PDOException;

/**
 * The only write path of an article's title, body, sensitive flag, version number and edit time (KB-01, VER-01, D-11).
 *
 * `create()` stores a general article as version 1 together with its version row. `update()` takes the version the
 * editor was opened with as a required argument and runs, in one transaction: authorise, lock the row, stale check,
 * normalise, no-op check, write the article and the immutable full snapshot of the new version. The order is fixed
 * and is never changed.
 *
 * Lock and numbering: the article row is re-read through the scoped query with `lockForUpdate()` (an archived
 * article is a not-found error, never written). The next version number is the locked row's number plus one, so
 * parallel saves queue on the lock, the first one wins and the others see a newer version and are refused with
 * StaleArticle (KB-08, D-09). No counter and no max() query is involved; UNIQUE (article_id, version_no) is the
 * backstop.
 *
 * What is saved: the body is stored normalised (D-10; prose lines normalised, code kept byte for byte). A save whose
 * title and normalised body equal the stored ones writes no version and does not move the edit time. A change of the
 * title alone is a content change (D-11). The sensitive flag is only ever cleared by the explicit manual clear of the
 * input (D-04, fail closed); a flag change without a content change writes no version.
 *
 * What is logged: the model writes metadata-only activity through the allowlist of KnowledgeArticle. This class calls
 * no logger and puts neither the title nor the body into any message, context or exception property. The title is
 * never scanned for secrets (D-02).
 *
 * Failure: a database failure inside the transaction (a query error whose message would carry the bound title and
 * body, or a failing commit) surfaces only as ArticleWriteFailed with a fixed message and the SQLSTATE, with no
 * previous exception. Every other exception passes through unchanged.
 */
final class SaveArticle
{
    public function __construct(private readonly MarkdownNormaliser $normaliser) {}

    /**
     * @throws AuthorizationException
     * @throws ArticleWriteFailed
     */
    public function create(User $actor, ArticleInput $input, bool $acknowledge = false): SaveResult
    {
        Gate::forUser($actor)->authorize('create', KnowledgeArticle::class);

        return $this->inTransaction(function () use ($actor, $input): SaveResult {
            $body = $this->normaliser->normalise($input->body);

            $article = new KnowledgeArticle;
            $article->forceFill([
                'kind' => ArticleKind::General,
                'title' => $input->title,
                'body_md' => $body,
                'sensitive' => $input->sensitive,
                'version_no' => 1,
                'edited_at' => now(),
            ])->save();

            $this->writeVersion($article, $actor);

            return new SaveResult(SaveOutcome::Created, $article, false);
        });
    }

    /**
     * @param  int  $expectedVersion  the version number the editor was opened with; no default, so no caller can skip the check
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException an archived or unknown article
     * @throws StaleArticle the stored version differs from the expected one
     * @throws ArticleWriteFailed
     */
    public function update(User $actor, KnowledgeArticle $article, ArticleInput $input, int $expectedVersion, bool $acknowledge = false): SaveResult
    {
        Gate::forUser($actor)->authorize('update', $article);

        $result = $this->inTransaction(function () use ($actor, $article, $input, $expectedVersion): SaveResult {
            // The scoped query: an archived article is a ModelNotFoundException.
            $locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->version_no !== $expectedVersion) {
                throw new StaleArticle($locked->version_no);
            }

            $body = $this->normaliser->normalise($input->body);
            $sensitive = $this->derivedSensitive($locked, $input, false);

            if ($locked->title === $input->title && $locked->body_md === $body) {
                if ($sensitive === $locked->sensitive) {
                    return new SaveResult(SaveOutcome::Unchanged, $locked, false);
                }

                $locked->forceFill(['sensitive' => $sensitive])->save();

                return new SaveResult(SaveOutcome::SensitivityChanged, $locked, false);
            }

            $locked->forceFill([
                'title' => $input->title,
                'body_md' => $body,
                'sensitive' => $sensitive,
                'version_no' => $locked->version_no + 1,
                'edited_at' => now(),
            ])->save();

            $this->writeVersion($locked, $actor);

            return new SaveResult(SaveOutcome::Updated, $locked, false);
        });

        // The caller's instance shows the stored value, whatever it showed before.
        $article->refresh();

        return new SaveResult($result->outcome, $article, $result->markedSensitive);
    }

    /**
     * The sensitive flag a save ends with (D-04, fail closed): an acknowledged ambiguous finding always marks it, only
     * the explicit manual clear clears it, a request to mark it marks it, and everything else keeps the stored flag.
     */
    private function derivedSensitive(KnowledgeArticle $locked, ArticleInput $input, bool $acknowledged): bool
    {
        if ($acknowledged) {
            return true;
        }

        if ($input->clearSensitive) {
            return false;
        }

        return $input->sensitive || $locked->sensitive;
    }

    /**
     * The immutable full snapshot of the article as it was just saved.
     */
    private function writeVersion(KnowledgeArticle $article, User $author): void
    {
        (new KnowledgeArticleVersion)->forceFill([
            'article_id' => $article->getKey(),
            'version_no' => $article->version_no,
            'title' => $article->title,
            'body_md' => $article->body_md,
            'author_id' => $author->getKey(),
        ])->save();
    }

    /**
     * Runs the writes in one transaction. A database failure leaves as ArticleWriteFailed, never with the original
     * message: the catch sits around the transaction call, so the rollback has already happened, and the original is
     * not passed on (its message holds the bound title and body).
     *
     * @param  Closure(): SaveResult  $callback
     */
    private function inTransaction(Closure $callback): SaveResult
    {
        try {
            return DB::transaction($callback);
        } catch (PDOException $e) {
            throw new ArticleWriteFailed((string) $e->getCode());
        }
    }
}
