<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\RedactVersion;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Guard\SecretFinding;
use App\Domain\Knowledge\Guard\SecretRule;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeVersionRedaction;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\RawSql;
use Tests\Support\SecretFixtures;

/*
 * Redacting a stored version (Phase 19, plan 19-09; VER-05, D-12, D-13): the line and whole scopes, the current
 * version following into the article, private keys, the limits, the open transaction setting and the content-free
 * logging. Every text is fictional; secret-shaped samples come only from SecretFixtures.
 */

const REDACT_VERSION_REASON = 'Example reason: a token was pasted';

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function redactVersionSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

function redactVersionArticle(string $id): KnowledgeArticle
{
    return redactVersionSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version rows of an article, oldest first.
 *
 * @return list<KnowledgeArticleVersion>
 */
function redactVersionRows(string $id): array
{
    return redactVersionSystem(static fn (): array => KnowledgeArticleVersion::query()
        ->where('article_id', $id)
        ->orderBy('version_no')
        ->get()
        ->all());
}

/**
 * The activity rows of an article with the given event, oldest first.
 *
 * @return list<Activity>
 */
function redactVersionActivity(string $id, string $event): array
{
    return redactVersionSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * Every stored row that could carry text of the article as one JSON text: the redaction rows, the version rows, the
 * article row and every activity row of the article.
 */
function redactVersionStored(string $id): string
{
    return (string) json_encode([
        DB::table('knowledge_version_redactions')->get()->all(),
        DB::table('knowledge_article_versions')->where('article_id', $id)->get()->all(),
        DB::table('knowledge_articles')->where('id', $id)->get()->all(),
        DB::table('activity_log')->get()->all(),
    ]);
}

/**
 * The number of redaction rows, to prove that a refused redaction wrote nothing.
 */
function redactVersionRedactionCount(): int
{
    return DB::table('knowledge_version_redactions')->count();
}

/**
 * The bodies of every version of an article, to prove that a refused redaction changed nothing.
 *
 * @return list<string>
 */
function redactVersionBodies(string $id): array
{
    return array_map(static fn (KnowledgeArticleVersion $version): string => $version->body_md, redactVersionRows($id));
}

/**
 * The rule keys of the findings on one line.
 *
 * @param  list<SecretFinding>  $findings
 * @return list<string>
 */
function redactVersionRulesOnLine(array $findings, int $line): array
{
    return array_values(array_map(
        static fn (SecretFinding $finding): string => $finding->rule->value,
        array_filter($findings, static fn (SecretFinding $finding): bool => $finding->line === $line),
    ));
}

/**
 * The field errors of a ValidationException the callback throws, or an empty list.
 *
 * @return array<string, list<string>>
 */
function redactVersionErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * Starts the live capture of every log write and proves it with a probe entry.
 */
function redactVersionCapturedLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
        $logs->append((string) $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
    });

    Log::info('redactVersion capture probe');

    expect(implode("\n", $logs->getArrayCopy()))->toContain('redactVersion capture probe');

    $logs->exchangeArray([]);

    return $logs;
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->redact = app(RedactVersion::class);
    $this->logs = redactVersionCapturedLogs();
});

it('redacts the selected line of an older version, keeps every other byte and the row, and records it without content', function (): void {
    $secret = SecretFixtures::githubToken();
    $body = "Example intro line\n{$secret}\n```\r\ncode line\r\n```\nExample outro line";
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => $body],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);
    $before = redactVersionRows($article->id);
    $articleBefore = redactVersionArticle($article->id);

    $result = $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '2', '  '.REDACT_VERSION_REASON.'  ');

    $after = redactVersionRows($article->id);
    $articleAfter = redactVersionArticle($article->id);
    $redactions = redactVersionSystem(static fn (): array => KnowledgeVersionRedaction::query()->get()->all());
    $events = redactVersionActivity($article->id, 'version_redacted');

    expect($after[0]->body_md)->toBe("Example intro line\n".RedactVersion::MARKER."\n```\r\ncode line\r\n```\nExample outro line")
        ->and($after[0]->id)->toBe($before[0]->id)
        ->and($after[0]->version_no)->toBe(1)
        ->and($after[0]->title)->toBe('Example first title')
        ->and($after[0]->author_id)->toBe($before[0]->author_id)
        ->and($after[0]->created_at?->equalTo($before[0]->created_at))->toBeTrue()
        ->and($after[1]->body_md)->toBe('Example second body')
        ->and($articleAfter->body_md)->toBe($articleBefore->body_md)
        ->and($articleAfter->redaction_no)->toBe(0)
        ->and($articleAfter->version_no)->toBe(2)
        ->and($result->versionNo)->toBe(1)
        ->and($result->current)->toBeFalse()
        ->and($result->redactedLines)->toBe([2])
        ->and($result->keyRanges)->toBe([])
        ->and($result->remainingFindings)->toBe([])
        ->and($redactions)->toHaveCount(1)
        ->and($redactions[0]->version_id)->toBe($before[0]->id)
        ->and($redactions[0]->actor_id)->toBe($this->admin->id)
        ->and($redactions[0]->scope)->toBe(RedactionScope::Lines)
        ->and($redactions[0]->reason_length)->toBe(mb_strlen(REDACT_VERSION_REASON))
        ->and($events)->toHaveCount(1)
        ->and($events[0]->causer_id)->toBe($this->admin->id)
        ->and($events[0]->properties->toArray())->toBe([
            'redacted_version_no' => 1,
            'scope' => 'lines',
            'current' => false,
            'reason_recorded' => true,
        ]);

    $stored = redactVersionStored($article->id);

    expect($stored)->not->toContain(REDACT_VERSION_REASON);

    foreach (SecretFixtures::needles($secret) as $needle) {
        expect($stored)->not->toContain($needle);
    }
});

it('redacts the whole current version, rewrites the article body and makes an older editor stale', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => 'Example first body'],
        ['title' => 'Example second title', 'body' => "Example line one\nExample line two"],
    ]);

    $result = $this->redact->handle($this->admin, $article, 2, RedactionScope::Whole, '', REDACT_VERSION_REASON);

    $row = redactVersionArticle($article->id);
    $versions = redactVersionRows($article->id);
    $events = redactVersionActivity($article->id, 'version_redacted');

    expect($result->current)->toBeTrue()
        ->and($row->body_md)->toBe(RedactVersion::MARKER)
        ->and($row->redaction_no)->toBe(1)
        ->and($row->version_no)->toBe(2)
        ->and($row->title)->toBe('Example second title')
        ->and($versions[1]->body_md)->toBe(RedactVersion::MARKER)
        ->and($versions[1]->title)->toBe('Example second title')
        ->and($versions[0]->body_md)->toBe('Example first body')
        ->and($events)->toHaveCount(1)
        ->and($events[0]->properties->toArray())->toBe([
            'redacted_version_no' => 2,
            'scope' => 'whole',
            'current' => true,
            'reason_recorded' => true,
        ]);

    $stale = null;

    try {
        app(SaveArticle::class)->update($this->admin, $article, ArticleInput::from('Example second title', 'Example edited body', false), 2, 0);
    } catch (StaleArticle $e) {
        $stale = $e;
    }

    expect($stale)->toBeInstanceOf(StaleArticle::class)
        ->and($stale->redactionChanged)->toBeTrue()
        ->and(redactVersionArticle($article->id)->body_md)->toBe(RedactVersion::MARKER)
        ->and(redactVersionRows($article->id))->toHaveCount(2);
});

it('extends a selected private key header to the end line of the key and finds nothing of the key afterwards', function (): void {
    $key = SecretFixtures::pemPrivateKey();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => SecretFixtures::bodyWith($key, 3)],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $result = $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '3', REDACT_VERSION_REASON);

    $lines = explode("\n", redactVersionRows($article->id)[0]->body_md);

    expect($result->redactedLines)->toBe([3, 4, 5, 6])
        ->and($result->keyRanges)->toBe([['from' => 3, 'to' => 6]])
        ->and($lines[1])->toBe('Example note number 2 about the setup.')
        ->and(array_slice($lines, 2, 4))->toBe(array_fill(0, 4, RedactVersion::MARKER))
        ->and($lines[6])->toBe('Example note number 4 about the setup.')
        ->and(array_filter($result->remainingFindings, static fn (SecretFinding $finding): bool => $finding->rule === SecretRule::PemPrivateKey))->toBe([]);

    foreach (SecretFixtures::needles($key) as $needle) {
        expect(redactVersionStored($article->id))->not->toContain($needle);
    }
});

it('leaves a key header behind when only its base64 line is selected, and reports what remains', function (): void {
    $key = SecretFixtures::pemPrivateKey();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => SecretFixtures::bodyWith($key, 3)],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $result = $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '5', REDACT_VERSION_REASON);

    expect($result->redactedLines)->toBe([5])
        ->and($result->keyRanges)->toBe([])
        ->and(redactVersionRulesOnLine($result->remainingFindings, 3))->toContain(SecretRule::PemPrivateKey->value);
});

it('bounds a key without an end line to the contiguous block', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => "Example intro\n-----BEGIN ".'PRIV'.'ATE KEY'."-----\nExample base64 line one\nExample base64 line two\n\nExample outro"],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $result = $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '2', REDACT_VERSION_REASON);

    expect($result->redactedLines)->toBe([2, 3, 4])
        ->and($result->keyRanges)->toBe([['from' => 2, 'to' => 4]])
        ->and(redactVersionRows($article->id)[0]->body_md)->toBe("Example intro\n".RedactVersion::MARKER."\n".RedactVersion::MARKER."\n".RedactVersion::MARKER."\n\nExample outro");
});

it('refuses a selection of only blank or already redacted lines and writes nothing', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => "Example one\n\n".RedactVersion::MARKER."\nExample four"],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);
    $bodies = redactVersionBodies($article->id);
    $count = redactVersionRedactionCount();

    foreach (['2', '3', '2-3'] as $selection) {
        $errors = redactVersionErrors(fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, $selection, REDACT_VERSION_REASON));

        expect(array_keys($errors))->toBe(['lines'], $selection)
            ->and($errors['lines'][0])->toBe(__('knowledge.redact.errors.nothing_to_redact'));
    }

    expect(redactVersionBodies($article->id))->toBe($bodies)
        ->and(redactVersionRedactionCount())->toBe($count);
});

it('refuses a redaction whose markers would push the body over the limit before the database sees it', function (): void {
    $body = implode("\n", array_fill(0, 49995, 'a'));
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => $body],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);
    $count = redactVersionRedactionCount();

    $errors = redactVersionErrors(fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '1-1000', REDACT_VERSION_REASON));

    expect(mb_strlen($body))->toBeLessThanOrEqual(KnowledgeArticle::MAX_BODY_LENGTH)
        ->and(array_keys($errors))->toBe(['lines'])
        ->and($errors['lines'][0])->toBe(__('knowledge.redact.errors.too_long', ['max' => KnowledgeArticle::MAX_BODY_LENGTH]))
        ->and(redactVersionRows($article->id)[0]->body_md)->toBe($body)
        ->and(redactVersionRedactionCount())->toBe($count);
});

it('reports a line the version does not have and an invalid line list as field errors', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => "Example one\nExample two"],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $missing = redactVersionErrors(fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '3', REDACT_VERSION_REASON));
    $invalid = redactVersionErrors(fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, 'abc', REDACT_VERSION_REASON));
    $empty = redactVersionErrors(fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '', REDACT_VERSION_REASON));

    expect($missing['lines'][0] ?? null)->toBe(__('knowledge.redact.errors.line_missing', ['n' => 3]))
        ->and($invalid['lines'][0] ?? null)->toBe(__('knowledge.redact.errors.lines_invalid'))
        ->and($empty['lines'][0] ?? null)->toBe(__('knowledge.redact.errors.lines_required'))
        ->and(redactVersionRedactionCount())->toBe(0);
});

it('redacts a version of an archived article and never changes the title', function (): void {
    $article = KnowledgeFixtures::archived(['title' => 'Example archived title', 'body_md' => "Example one\nExample two", 'version_no' => 2]);

    $result = $this->redact->handle($this->admin, $article, 1, RedactionScope::Whole, '', REDACT_VERSION_REASON);

    $row = redactVersionArticle($article->id);
    $versions = redactVersionRows($article->id);

    expect($result->current)->toBeFalse()
        ->and($row->trashed())->toBeTrue()
        ->and($row->title)->toBe('Example archived title')
        ->and($versions[0]->body_md)->toBe(RedactVersion::MARKER)
        ->and($versions[0]->title)->toBe('Example archived title')
        ->and($versions[1]->body_md)->toBe("Example one\nExample two");
});

it('refuses a version number the article does not have', function (): void {
    $article = KnowledgeFixtures::article(['body_md' => 'Example body']);

    expect(fn () => $this->redact->handle($this->admin, $article, 5, RedactionScope::Whole, '', REDACT_VERSION_REASON))
        ->toThrow(ModelNotFoundException::class)
        ->and(redactVersionRedactionCount())->toBe(0);
});

it('requires a reason of one to 500 characters without a NUL byte, and writes nothing otherwise', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => 'Example first body'],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);
    $bodies = redactVersionBodies($article->id);

    $cases = [
        'blank' => ['   ', __('knowledge.redact.errors.reason_required')],
        'empty' => ['', __('knowledge.redact.errors.reason_required')],
        'not text' => [null, __('knowledge.redact.errors.reason_required')],
        'too long' => [str_repeat('a', RedactVersion::MAX_REASON_LENGTH + 1), __('knowledge.redact.errors.reason_too_long', ['max' => RedactVersion::MAX_REASON_LENGTH])],
        'NUL byte' => ["Example reason\0 with a NUL", __('knowledge.errors.nul_character')],
    ];

    foreach ($cases as $name => [$reason, $message]) {
        $errors = redactVersionErrors(fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Whole, '', $reason));

        expect(array_keys($errors))->toBe(['reason'], $name)
            ->and($errors['reason'][0])->toBe($message, $name);
    }

    $this->redact->handle($this->admin, $article, 1, RedactionScope::Whole, '', str_repeat('a', RedactVersion::MAX_REASON_LENGTH));

    $redactions = DB::table('knowledge_version_redactions')->get();

    expect($bodies)->toBe(['Example first body', 'Example second body'])
        ->and($redactions)->toHaveCount(1)
        ->and((int) $redactions[0]->reason_length)->toBe(RedactVersion::MAX_REASON_LENGTH);
});

it('switches the redaction setting off again, so a raw body rewrite is refused afterwards', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => 'Example first body'],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $this->redact->handle($this->admin, $article, 1, RedactionScope::Whole, '', REDACT_VERSION_REASON);

    $versionId = redactVersionRows($article->id)[1]->id;

    RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_versions')->where('id', $versionId)->update(['body_md' => 'Example rewritten body']));

    expect(redactVersionRows($article->id)[1]->body_md)->toBe('Example second body');
});

it('refuses a Partner and a user without a role and writes nothing', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => 'Example first body'],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);
    $bodies = redactVersionBodies($article->id);

    foreach ([Canary::partnerFor(Canary::twoClients()[0]), Canary::userWithoutRole(null)] as $actor) {
        expect(fn () => $this->redact->handle($actor, $article, 1, RedactionScope::Whole, '', REDACT_VERSION_REASON))
            ->toThrow(AuthorizationException::class);
    }

    expect(redactVersionBodies($article->id))->toBe($bodies)
        ->and(redactVersionRedactionCount())->toBe(0)
        ->and(redactVersionActivity($article->id, 'version_redacted'))->toBe([]);
});

it('puts neither the line text, the reason nor a secret needle into a message, a stored row, the result or a log line', function (): void {
    $secret = SecretFixtures::passwordAssignment();
    $reason = 'Example reason '.Canary::canary('redact_reason');
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => SecretFixtures::bodyWith($secret, 2)],
        ['title' => 'Example second title', 'body' => 'Example second body'],
    ]);

    $messages = [];

    foreach ([
        fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '99', $reason),
        fn () => $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '1', $reason."\0"),
    ] as $failing) {
        $messages[] = (string) json_encode(redactVersionErrors($failing));
    }

    $result = $this->redact->handle($this->admin, $article, 1, RedactionScope::Lines, '2', $reason);

    $watched = [$reason, ...SecretFixtures::needles($secret)];
    $texts = [
        ...$messages,
        redactVersionStored($article->id),
        (string) json_encode($result->remainingFindings),
        implode("\n", $this->logs->getArrayCopy()),
    ];

    foreach ($watched as $text) {
        foreach ($texts as $haystack) {
            expect($haystack)->not->toContain($text);
        }
    }
});
