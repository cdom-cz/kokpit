<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Guard\SecretScanner;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeVersionRedaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;

/**
 * Removes a secret that reached a stored version, the one controlled exception to the immutable history (VER-05,
 * D-12, D-13).
 *
 * What it rewrites: the `body_md` of the chosen version, as the selected lines (or the whole body) replaced by MARKER.
 * The row, its number, title, author and time stay, the title is never redacted, and one append-only
 * `knowledge_version_redactions` row records who, which version, the scope and the LENGTH of the reason. When the
 * version is the current one, the article body is rewritten the same way and `redaction_no` is raised by one in the
 * same transaction, so the article and its newest version never disagree and every editor or restore modal opened
 * before is stale (SaveArticle compares the number, D-13). Any version can be redacted, the current one and the
 * versions of archived articles included. A redaction does not cascade: later versions that still hold the same text
 * keep it until each is redacted on its own (owner decision OD-3).
 *
 * The reason is required, but its text is never persisted (owner decision OD-1): it may hold what the Admin pasted.
 * Only its length is kept, and the text is dropped at once. It reaches no table, no activity row, no exception message,
 * no result and no log line.
 *
 * The database allows the rewrite only inside a transaction that switched `kokpit.redacting_knowledge_version` on, for
 * a change of `body_md` alone. This class is the only place that names the setting, and with SaveArticle (which writes
 * the body of a new version) the second reviewed writer of a body; the Arch test pins both lists. The setting is
 * switched off again right after the two updates, so nothing later in the same (possibly enclosing) transaction can
 * rewrite a version. Both rewrites use the query builder: no model event fires and no attribute activity is written.
 * Hiding a body write in raw SQL elsewhere is not allowed.
 *
 * Lock order (research Pitfall 7): the article row first (archived articles included), then the version row, the same
 * as RestoreVersion and SaveArticle. A restore that starts while a redaction holds the article lock waits and then
 * restores the redacted body.
 *
 * Private keys: a selected line that holds a private key header extends to the END line of that key (at most
 * PEM_BLOCK_MAX_LINES lines further), or, when there is no END line, to the last non-blank line of the block, so a
 * line selection never leaves the rest of a key behind. Blank lines and lines that already hold the marker are
 * skipped. The redacted body is rescanned after the commit and the findings that remain are returned (rule and line
 * only), because a redaction by line cannot know what else is a secret.
 *
 * Failure: a database failure leaves as ArticleWriteFailed with the SQLSTATE only; the original message would carry
 * the bound body. Validation errors name a field and a number, never any text.
 */
final class RedactVersion
{
    public const string MARKER = '[REDIGOVÁNO]';

    public const int PEM_BLOCK_MAX_LINES = 200;

    public const int MAX_REASON_LENGTH = 500;

    private const string PEM_BEGIN = '/-----BEGIN [A-Z0-9 ]{0,100}PRIVATE KEY[A-Z ]{0,20}-----/';

    private const string PEM_END = '/-----END [A-Z0-9 ]{0,100}PRIVATE KEY[A-Z ]{0,20}-----/';

    public function __construct(private readonly SecretScanner $scanner) {}

    /**
     * @param  string  $lines  the typed line list (`3, 7-9`); ignored for the whole scope
     * @param  mixed  $reason  the typed reason; only its length is kept
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException an unknown article or a version number the article does not have
     * @throws ValidationException a field error on `reason` or `lines`
     * @throws ArticleWriteFailed
     */
    public function handle(
        User $actor,
        KnowledgeArticle $article,
        int $versionNo,
        RedactionScope $scope,
        string $lines,
        mixed $reason,
    ): RedactResult {
        Gate::forUser($actor)->authorize('update', $article);

        $reasonLength = $this->reasonLength($reason);
        $reason = null;

        try {
            [$body, $current, $redacted, $ranges] = DB::transaction(function () use ($actor, $article, $versionNo, $scope, $lines, $reasonLength): array {
                // Archived articles can be redacted too; the article lock comes first, then the version.
                $locked = KnowledgeArticle::query()->withTrashed()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();

                Gate::forUser($actor)->authorize('update', $locked);

                $version = KnowledgeArticleVersion::query()
                    ->where('article_id', $locked->getKey())
                    ->where('version_no', $versionNo)
                    ->firstOrFail();

                $parts = preg_split('/(\r\n|\r|\n)/', $version->body_md, -1, PREG_SPLIT_DELIM_CAPTURE);

                if ($parts === false) {
                    throw new RuntimeException('The version body could not be split into lines.');
                }

                $lineCount = intdiv(count($parts) + 1, 2);

                if ($scope === RedactionScope::Whole) {
                    $new = self::MARKER;
                    $redacted = range(1, $lineCount);
                    $ranges = [];
                } else {
                    [$redacted, $ranges] = $this->selectLines($parts, RedactionLines::parse($lines, $lineCount));

                    if ($redacted === []) {
                        throw ValidationException::withMessages(['lines' => __('knowledge.redact.errors.nothing_to_redact')]);
                    }

                    foreach ($redacted as $number) {
                        $parts[($number - 1) * 2] = self::MARKER;
                    }

                    $new = implode('', $parts);
                }

                // The body limit is checked here, so the database CHECK never has to refuse it.
                if (mb_strlen($new) > KnowledgeArticle::MAX_BODY_LENGTH) {
                    throw ValidationException::withMessages(['lines' => __('knowledge.redact.errors.too_long', ['max' => KnowledgeArticle::MAX_BODY_LENGTH])]);
                }

                if ($new === $version->body_md) {
                    throw ValidationException::withMessages(['lines' => __('knowledge.redact.errors.nothing_to_redact')]);
                }

                $current = $versionNo === $locked->version_no;

                (new KnowledgeVersionRedaction)->forceFill([
                    'version_id' => $version->getKey(),
                    'actor_id' => $actor->getKey(),
                    'scope' => $scope,
                    'reason_length' => $reasonLength,
                ])->save();

                DB::select("select set_config('kokpit.redacting_knowledge_version', 'on', true)");

                KnowledgeArticleVersion::query()->whereKey($version->getKey())->toBase()->update(['body_md' => $new]);

                if ($current) {
                    KnowledgeArticle::query()->whereKey($locked->getKey())->toBase()->update([
                        'body_md' => $new,
                        'redaction_no' => $locked->redaction_no + 1,
                    ]);
                }

                DB::select("select set_config('kokpit.redacting_knowledge_version', 'off', true)");

                activity()
                    ->useLog($locked->getMorphClass())
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('version_redacted')
                    ->withProperties([
                        'redacted_version_no' => $versionNo,
                        'scope' => $scope->value,
                        'current' => $current,
                        'reason_recorded' => true,
                    ])
                    ->log('version_redacted');

                return [$new, $current, $redacted, $ranges];
            });
        } catch (PDOException $e) {
            throw new ArticleWriteFailed((string) $e->getCode());
        }

        // The caller's instance shows the stored value, whatever it showed before.
        $article->refresh();

        return new RedactResult($versionNo, $current, $redacted, $ranges, $this->scanner->scan($body));
    }

    /**
     * The length of the trimmed reason. The text itself is not returned and not kept by the caller.
     *
     * @throws ValidationException
     */
    private function reasonLength(mixed $reason): int
    {
        if (is_string($reason) && str_contains($reason, "\0")) {
            throw ValidationException::withMessages(['reason' => __('knowledge.errors.nul_character')]);
        }

        $trimmed = is_string($reason) ? trim($reason) : '';

        if ($trimmed === '') {
            throw ValidationException::withMessages(['reason' => __('knowledge.redact.errors.reason_required')]);
        }

        $length = mb_strlen($trimmed);

        if ($length > self::MAX_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => __('knowledge.redact.errors.reason_too_long', ['max' => self::MAX_REASON_LENGTH])]);
        }

        return $length;
    }

    /**
     * The lines that end up redacted: the selected ones, a private key header extended to the end of its key, minus
     * blank lines and lines that already hold the marker.
     *
     * @param  array<int, string>  $parts  the body split at its separators: lines at the even positions
     * @param  list<int>  $selected  1-based line numbers
     * @return array{list<int>, list<array{from: int, to: int}>} the lines to redact and the key ranges the selection grew to
     */
    private function selectLines(array $parts, array $selected): array
    {
        $count = intdiv(count($parts) + 1, 2);
        $line = static fn (int $number): string => $parts[($number - 1) * 2];

        $chosen = [];
        $ranges = [];

        foreach ($selected as $number) {
            $chosen[$number] = true;

            // A line inside a key range found earlier needs no search of its own.
            if ($this->insideRange($ranges, $number) || preg_match(self::PEM_BEGIN, $line($number)) !== 1) {
                continue;
            }

            $limit = min($count, $number + self::PEM_BLOCK_MAX_LINES);
            $end = null;

            for ($next = $number + 1; $next <= $limit; $next++) {
                if (preg_match(self::PEM_END, $line($next)) === 1) {
                    $end = $next;

                    break;
                }
            }

            if ($end === null) {
                // No END line: the rest of the contiguous block, capped like the search.
                $end = $number;

                for ($next = $number + 1; $next <= $limit && trim($line($next)) !== ''; $next++) {
                    $end = $next;
                }
            }

            if ($end > $number) {
                $ranges[] = ['from' => $number, 'to' => $end];

                for ($covered = $number; $covered <= $end; $covered++) {
                    $chosen[$covered] = true;
                }
            }
        }

        $redacted = [];

        foreach (array_keys($chosen) as $number) {
            $text = trim($line($number));

            if ($text !== '' && $text !== self::MARKER) {
                $redacted[] = $number;
            }
        }

        sort($redacted);

        return [$redacted, $ranges];
    }

    /**
     * @param  list<array{from: int, to: int}>  $ranges
     */
    private function insideRange(array $ranges, int $number): bool
    {
        foreach ($ranges as $range) {
            if ($number >= $range['from'] && $number <= $range['to']) {
                return true;
            }
        }

        return false;
    }
}
