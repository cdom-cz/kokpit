<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Clients\Models\Client;
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
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
 * The classification (ORG-03, ORG-04, D-04, D-06): when the input carries one, the folder, client, project and
 * knowledge tags are written inside the same transaction, after the stale checks and the guard decision, so a refused
 * save changes none of them. A change of the classification alone writes no version and leaves the edit time as it
 * was (ClassificationChanged). A null classification keeps the stored one. Only knowledge tags are touched.
 *
 * A restore (D-11, VER-03) goes through `update()` with `$restoredFromVersionNo`: the new version row records the
 * version it copied, and one `version_restored` activity row holds the two version numbers only. Restoring the text
 * the article already has writes neither a version nor that row.
 *
 * The secret guard (SEC-01 to SEC-03): every content-changing save scans the normalised body, never the title (D-02).
 * A Blocking finding refuses the save with no way around it (D-01), an Ambiguous one refuses it unless this one call
 * passes `$acknowledge`, which marks the article sensitive and writes one `secret_acknowledged` activity row holding
 * the rule keys and the new version number only (D-03, D-04). The acknowledgment is a per-call argument and is never
 * stored. A save without a content change is not scanned (nothing new was typed).
 *
 * Phase 20 appends one step to that order, only in a save that writes a version (a new article, a content change, a
 * restore): after the guard decision the image rows the body references are locked, and a reference to an image of
 * another article, or to a pending image of another user, refuses the save with a field error on `body`; after the
 * version row the Admin's pending images are bound to the article and every referenced image is indexed for that
 * version (D-02, D-04, D-16). A reference to an unknown or deleted image is skipped, so a restore never fails on it
 * (D-18). The no-op, classification-only and sensitivity-only branches write no version and run no image step.
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
        private readonly BindArticleImages $images,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws SecretGuardRefused
     * @throws ValidationException a folder, client or project of the classification that does not exist
     * @throws ArticleWriteFailed
     */
    public function create(User $actor, ArticleInput $input, bool $acknowledge = false): SaveResult
    {
        Gate::forUser($actor)->authorize('create', KnowledgeArticle::class);

        return $this->inTransaction(function () use ($actor, $input, $acknowledge): SaveResult {
            $classification = $input->classification;

            if ($classification !== null) {
                $this->assertClassificationTargets($classification);
            }

            $body = $this->normaliser->normalise($input->body);
            $rules = $this->acknowledgedRules($body, $acknowledge);
            $images = $this->images->lockReferenced($body, null, $actor);

            $article = new KnowledgeArticle;
            $article->forceFill([
                'kind' => ArticleKind::General,
                'title' => $input->title,
                'body_md' => $body,
                'sensitive' => $input->sensitive || $rules !== [],
                'version_no' => 1,
                'edited_at' => now(),
                ...($classification === null ? [] : $this->classificationColumns($classification)),
            ])->save();

            if ($classification !== null) {
                $this->syncKnowledgeTags($article, $classification->tags);
            }

            $version = $this->writeVersion($article, $actor);
            $this->images->bindAndIndex($article, $version, $images);
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
     * @throws ValidationException a folder, client or project of the classification that does not exist
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

        $result = $this->inTransaction(function () use ($actor, $article, $input, $expectedVersion, $expectedRedactionNo, $acknowledge, $restoredFromVersionNo): SaveResult {
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

            $classification = $input->classification;

            if ($classification !== null) {
                $this->assertClassificationTargets($classification);
            }

            $body = $this->normaliser->normalise($input->body);

            if ($locked->title === $input->title && $locked->body_md === $body) {
                $sensitive = $this->derivedSensitive($locked, $input, false);

                if ($classification !== null && $this->classificationDiffers($locked, $classification)) {
                    $columns = $this->classificationColumns($classification);

                    if ($sensitive !== $locked->sensitive) {
                        $columns['sensitive'] = $sensitive;
                    }

                    $locked->forceFill($columns)->save();
                    $this->syncKnowledgeTags($locked, $classification->tags);

                    return new SaveResult(SaveOutcome::ClassificationChanged, $locked, false);
                }

                if ($sensitive === $locked->sensitive) {
                    return new SaveResult(SaveOutcome::Unchanged, $locked, false);
                }

                $locked->forceFill(['sensitive' => $sensitive])->save();

                return new SaveResult(SaveOutcome::SensitivityChanged, $locked, false);
            }

            $rules = $this->acknowledgedRules($body, $acknowledge);
            $images = $this->images->lockReferenced($body, $locked, $actor);

            $locked->forceFill([
                'title' => $input->title,
                'body_md' => $body,
                'sensitive' => $this->derivedSensitive($locked, $input, $rules !== []),
                'version_no' => $locked->version_no + 1,
                'edited_at' => now(),
                ...($classification === null ? [] : $this->classificationColumns($classification)),
            ])->save();

            if ($classification !== null) {
                $this->syncKnowledgeTags($locked, $classification->tags);
            }

            $version = $this->writeVersion($locked, $actor, $restoredFromVersionNo);
            $this->images->bindAndIndex($locked, $version, $images);
            $this->writeAcknowledgment($locked, $actor, $rules);
            $this->writeRestoreMarker($locked, $actor, $restoredFromVersionNo);

            return new SaveResult(SaveOutcome::Updated, $locked, $rules !== []);
        });

        // The caller's instance shows the stored value, whatever it showed before.
        $article->refresh();

        return new SaveResult($result->outcome, $article, $result->markedSensitive);
    }

    /**
     * The folder, client and project a classification names must exist, and the project must belong to the client
     * (D-06). An archived client or project is still valid here: a stored link to one stays, and the form offers
     * only the selectable ones. A missing target is a field error, never a raw database error.
     *
     * @throws ValidationException
     */
    private function assertClassificationTargets(ArticleClassification $classification): void
    {
        if ($classification->folderId !== null && ! KnowledgeFolder::query()->whereKey($classification->folderId)->exists()) {
            throw ValidationException::withMessages(['folder_id' => __('knowledge.links.errors.folder_missing')]);
        }

        if ($classification->clientId !== null && ! Client::query()->withTrashed()->whereKey($classification->clientId)->exists()) {
            throw ValidationException::withMessages(['client_id' => __('knowledge.links.errors.client_missing')]);
        }

        if ($classification->projectId !== null && ! Project::query()->withTrashed()->whereKey($classification->projectId)->where('client_id', $classification->clientId)->exists()) {
            throw ValidationException::withMessages(['project_id' => __('knowledge.links.errors.project_mismatch')]);
        }
    }

    /**
     * @return array{folder_id: string|null, client_id: string|null, project_id: string|null}
     */
    private function classificationColumns(ArticleClassification $classification): array
    {
        return [
            'folder_id' => $classification->folderId,
            'client_id' => $classification->clientId,
            'project_id' => $classification->projectId,
        ];
    }

    /**
     * Whether the classification differs from the stored one: another folder, client or project, or another tag set
     * (compared case-insensitively, as the tags are merged).
     */
    private function classificationDiffers(KnowledgeArticle $locked, ArticleClassification $classification): bool
    {
        if ($locked->folder_id !== $classification->folderId
            || $locked->client_id !== $classification->clientId
            || $locked->project_id !== $classification->projectId) {
            return true;
        }

        $stored = $locked->tagsWithType(TagType::Knowledge->value)
            ->pluck('name')
            ->map(static fn (mixed $name): string => mb_strtolower((string) $name))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $wanted = collect($classification->tags)
            ->map(static fn (string $name): string => mb_strtolower($name))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $stored !== $wanted;
    }

    /**
     * Writes the knowledge tags of the article (D-04) and only those: client, project and task tags stay. A name
     * that matches an existing knowledge tag case-insensitively takes that tag's stored spelling. Tag names are
     * never logged. Two parallel saves that create the same new tag may leave a duplicate: there is no unique index
     * on tags and one Admin makes it rare (accepted).
     *
     * @param  list<string>  $names
     */
    private function syncKnowledgeTags(KnowledgeArticle $article, array $names): void
    {
        $spelling = [];

        foreach (Tag::getWithType(TagType::Knowledge->value)->pluck('name') as $name) {
            $spelling[mb_strtolower((string) $name)] ??= (string) $name;
        }

        $mapped = array_map(static fn (string $name): string => $spelling[mb_strtolower($name)] ?? $name, $names);

        $article->syncTagsWithType($mapped, TagType::Knowledge->value);
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
     * The one history entry of a restore: the two version numbers, never any content (D-11, VER-03). A save that
     * restores identical text writes no version, so it returns before reaching this method and writes no entry.
     */
    private function writeRestoreMarker(KnowledgeArticle $article, User $actor, ?int $restoredFromVersionNo): void
    {
        if ($restoredFromVersionNo === null) {
            return;
        }

        activity()
            ->useLog($article->getMorphClass())
            ->performedOn($article)
            ->causedBy($actor)
            ->event('version_restored')
            ->withProperties(['from_version_no' => $restoredFromVersionNo, 'version_no' => $article->version_no])
            ->log('version_restored');
    }

    /**
     * The immutable full snapshot of the article as it was just saved. `$restoredFromVersionNo` is the number of the
     * version a restore copied, and null for every other save.
     */
    private function writeVersion(KnowledgeArticle $article, User $author, ?int $restoredFromVersionNo = null): KnowledgeArticleVersion
    {
        $version = new KnowledgeArticleVersion;
        $version->forceFill([
            'article_id' => $article->getKey(),
            'version_no' => $article->version_no,
            'title' => $article->title,
            'body_md' => $article->body_md,
            'author_id' => $author->getKey(),
            'restored_from_version_no' => $restoredFromVersionNo,
        ])->save();

        return $version;
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
