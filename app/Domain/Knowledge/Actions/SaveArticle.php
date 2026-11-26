<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Exceptions\SecretGuardRefused;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Guard\SecretFinding;
use App\Domain\Knowledge\Guard\SecretScanner;
use App\Domain\Knowledge\Guard\Severity;
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
 * editor was opened with and the redaction number it was opened with as required arguments and runs, in one
 * transaction: authorise, lock the row, stale check (version, then redaction number, D-13), normalise, no-op check,
 * secret scan, write the article and the immutable full snapshot of the new version. The order is fixed and is
 * never changed.
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
 * The secret guard (SEC-01 to SEC-03): every content-changing save scans the normalised body, never the title (D-02).
 * A Blocking finding refuses the save with no way around it (D-01), an Ambiguous one refuses it unless this one call
 * passes `$acknowledge`, which marks the article sensitive and writes one `secret_acknowledged` activity row holding
 * the rule keys and the new version number only (D-03, D-04). The acknowledgment is a per-call argument and is never
 * stored. A save without a content change is not scanned (nothing new was typed).
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
    public function __construct(
        private readonly MarkdownNormaliser $normaliser,
        private readonly SecretScanner $scanner,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws SecretGuardRefused
     * @throws ArticleWriteFailed
     */
    public function create(User $actor, ArticleInput $input, bool $acknowledge = false): SaveResult
    {
        Gate::forUser($actor)->authorize('create', KnowledgeArticle::class);

        return $this->inTransaction(function () use ($actor, $input, $acknowledge): SaveResult {
            $body = $this->normaliser->normalise($input->body);
            $rules = $this->acknowledgedRules($body, $acknowledge);

            $article = new KnowledgeArticle;
            $article->forceFill([
                'kind' => ArticleKind::General,
                'title' => $input->title,
                'body_md' => $body,
                'sensitive' => $input->sensitive || $rules !== [],
                'version_no' => 1,
                'edited_at' => now(),
            ])->save();

            $this->writeVersion($article, $actor);
            $this->writeAcknowledgment($article, $actor, $rules);

            return new SaveResult(SaveOutcome::Created, $article, $rules !== []);
        });
    }

    /**
     * @param  int  $expectedVersion  the version number the editor was opened with; no default, so no caller can skip the check
     * @param  int  $expectedRedactionNo  the redaction number the editor was opened with; no default either, so an
     *                                    editor opened before a redaction of the current version can never put the old text back (D-13)
     * @param  int|null  $restoredFromVersionNo  the number of the version this save restores, or null for every other save
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException an archived or unknown article
     * @throws StaleArticle the stored version or redaction number differs from the expected one
     * @throws SecretGuardRefused
     * @throws ArticleWriteFailed
     */
    public function update(
        User $actor,
        KnowledgeArticle $article,
        ArticleInput $input,
        int $expectedVersion,
        int $expectedRedactionNo,
        bool $acknowledge = false,
        ?int $restoredFromVersionNo = null,
    ): SaveResult {
        Gate::forUser($actor)->authorize('update', $article);

        $result = $this->inTransaction(function () use ($actor, $article, $input, $expectedVersion, $expectedRedactionNo, $acknowledge): SaveResult {
            // The scoped query: an archived article is a ModelNotFoundException.
            $locked = KnowledgeArticle::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->version_no !== $expectedVersion) {
                throw new StaleArticle($locked->version_no);
            }

            // D-13: a redaction of the current version changes the article without a new version, so the version
            // number alone cannot tell an editor opened before it. The version check comes first.
            if ($locked->redaction_no !== $expectedRedactionNo) {
                throw new StaleArticle($locked->version_no, redactionChanged: true);
            }

            $body = $this->normaliser->normalise($input->body);

            if ($locked->title === $input->title && $locked->body_md === $body) {
                $sensitive = $this->derivedSensitive($locked, $input, false);

                if ($sensitive === $locked->sensitive) {
                    return new SaveResult(SaveOutcome::Unchanged, $locked, false);
                }

                $locked->forceFill(['sensitive' => $sensitive])->save();

                return new SaveResult(SaveOutcome::SensitivityChanged, $locked, false);
            }

            $rules = $this->acknowledgedRules($body, $acknowledge);

            $locked->forceFill([
                'title' => $input->title,
                'body_md' => $body,
                'sensitive' => $this->derivedSensitive($locked, $input, $rules !== []),
                'version_no' => $locked->version_no + 1,
                'edited_at' => now(),
            ])->save();

            $this->writeVersion($locked, $actor);
            $this->writeAcknowledgment($locked, $actor, $rules);

            return new SaveResult(SaveOutcome::Updated, $locked, $rules !== []);
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
     * The guard decision for a normalised body (R-18-05-5). Returns the sorted rule keys of the Ambiguous findings this
     * save acknowledges, or an empty list when the body is clean or `$acknowledge` changes nothing.
     *
     * @return list<string>
     *
     * @throws SecretGuardRefused a Blocking finding whatever `$acknowledge` says, or an Ambiguous one without it
     */
    private function acknowledgedRules(string $body, bool $acknowledge): array
    {
        $findings = $this->scanner->scan($body);

        $ambiguous = array_values(array_filter(
            $findings,
            static fn (SecretFinding $finding): bool => $finding->severity() === Severity::Ambiguous,
        ));

        if ($findings === []) {
            return [];
        }

        if (count($ambiguous) < count($findings) || ! $acknowledge) {
            throw new SecretGuardRefused($findings, hash('sha256', $body));
        }

        $rules = array_unique(array_map(static fn (SecretFinding $finding): string => $finding->rule->value, $ambiguous));
        sort($rules);

        return $rules;
    }

    /**
     * The one audit row of an acknowledged save: the rule keys and the version number, never any content (SEC-03).
     *
     * @param  list<string>  $rules
     */
    private function writeAcknowledgment(KnowledgeArticle $article, User $actor, array $rules): void
    {
        if ($rules === []) {
            return;
        }

        activity()
            ->useLog($article->getMorphClass())
            ->performedOn($article)
            ->causedBy($actor)
            ->event('secret_acknowledged')
            ->withProperties(['rules' => $rules, 'version_no' => $article->version_no])
            ->log('secret_acknowledged');
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
