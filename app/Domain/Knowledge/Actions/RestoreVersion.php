<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Exceptions\SecretGuardRefused;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PDOException;

/**
 * Brings an earlier version of an article back as the newest version (VER-03, D-11, SEC-01 restore clause).
 *
 * A restore is a save: the title and the body of version k go through `SaveArticle::update()` with the version and
 * the redaction number the restore modal was opened with, so the stale check, the normaliser, the no-op check and the
 * secret guard run exactly as for an editor save. An unambiguous secret in the old text refuses the restore with
 * nothing written, an ambiguous finding needs this one restore's acknowledgment (which marks the article sensitive
 * and is logged without content), an archived article is a not-found error, and restoring the text the article
 * already has writes nothing. The new version records the number it copied in `restored_from_version_no`; versions 1
 * to n stay untouched. The input carries no classification, so only title and body change; folder, links, tags and
 * the stored sensitive flag stay.
 *
 * Lock order (research Pitfall 7): the article row is locked first, and only then the version is read. A redaction
 * of the same version (RedactVersion) takes the same locks in the same order, so a restore that starts while a
 * redaction is running waits on the article row and then reads the redacted body, never the original. SaveArticle
 * takes the article lock first as well.
 *
 * Failure: a database failure leaves as ArticleWriteFailed with the SQLSTATE only, like every save, because the
 * original message would carry the bound body.
 */
final class RestoreVersion
{
    public function __construct(private readonly SaveArticle $save) {}

    /**
     * @param  int  $expectedVersion  the version number the restore modal was opened with
     * @param  int  $expectedRedactionNo  the redaction number the restore modal was opened with
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException an archived or unknown article, or a version number the article does not have
     * @throws StaleArticle
     * @throws SecretGuardRefused
     * @throws ArticleWriteFailed
     */
    public function handle(
        User $actor,
        KnowledgeArticle $article,
        int $versionNo,
        int $expectedVersion,
        int $expectedRedactionNo,
        bool $acknowledge = false,
    ): SaveResult {
        Gate::forUser($actor)->authorize('update', $article);

        try {
            $result = DB::transaction(function () use ($actor, $article, $versionNo, $expectedVersion, $expectedRedactionNo, $acknowledge): SaveResult {
                // The scoped query: an archived article is a ModelNotFoundException. The article lock comes first.
                $locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();

                Gate::forUser($actor)->authorize('update', $locked);

                // Only now is the version read: a redaction that held the lock has finished and is visible.
                $version = KnowledgeArticleVersion::query()
                    ->where('article_id', $locked->getKey())
                    ->where('version_no', $versionNo)
                    ->firstOrFail();

                return $this->save->update(
                    $actor,
                    $locked,
                    ArticleInput::from($version->title, $version->body_md, false),
                    $expectedVersion,
                    $expectedRedactionNo,
                    $acknowledge,
                    restoredFromVersionNo: $versionNo,
                );
            });
        } catch (PDOException $e) {
            throw new ArticleWriteFailed((string) $e->getCode());
        }

        // The caller's instance shows the stored value, whatever it showed before.
        $article->refresh();

        return new SaveResult($result->outcome, $article, $result->markedSensitive);
    }
}
