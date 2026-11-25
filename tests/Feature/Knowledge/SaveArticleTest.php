<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
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

/*
 * The one write path of the knowledge articles (Phase 18, P18-05): versions, no-op, stale save, authorisation, the
 * secret guard and the content-free logging. Every title and body is fictional; secret-shaped samples come only from
 * SecretFixtures, assembled at runtime.
 *
 * Every test runs under a live capture of every log write (saveArticleCapturedLogs): the capture is proven with a
 * probe entry, and the afterEach hook asserts that no captured entry holds any text a test put on its watch list
 * (the title, the body, the fragments of a fixture secret).
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function saveArticleSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article row, archived ones included.
 */
function saveArticleRow(string $id): KnowledgeArticle
{
    return saveArticleSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version rows of an article, oldest first.
 *
 * @return list<KnowledgeArticleVersion>
 */
function saveArticleVersions(string $id): array
{
    return saveArticleSystem(static fn (): array => KnowledgeArticleVersion::query()
        ->where('article_id', $id)
        ->orderBy('version_no')
        ->get()
        ->all());
}

/**
 * Every activity row of the article, as the whole stored row serialised, oldest first.
 *
 * @return list<Activity>
 */
function saveArticleActivity(string $id): array
{
    return saveArticleSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * The number of article rows and version rows, to prove that a refused save wrote nothing.
 *
 * @return array{articles: int, versions: int, activity: int}
 */
function saveArticleCounts(): array
{
    return saveArticleSystem(static fn (): array => [
        'articles' => KnowledgeArticle::query()->withTrashed()->count(),
        'versions' => KnowledgeArticleVersion::query()->count(),
        'activity' => Activity::query()->count(),
    ]);
}

/**
 * A title and a body that no other test shares.
 *
 * @return array{string, string}
 */
function saveArticleText(string $label): array
{
    return [Canary::canary($label.'_title'), 'Example body line. '.Canary::canary($label.'_body')];
}

/**
 * The captured log entries (message and context, both flattened to text).
 */
function saveArticleLogs(): ArrayObject
{
    static $logs = null;

    return $logs ??= new ArrayObject;
}

/**
 * The texts that must appear in no captured log entry.
 */
function saveArticleWatched(): ArrayObject
{
    static $watched = null;

    return $watched ??= new ArrayObject;
}

/**
 * Puts texts on the watch list of the running test.
 */
function saveArticleWatch(string ...$texts): void
{
    foreach ($texts as $text) {
        saveArticleWatched()->append($text);
    }
}

/**
 * A context value as text, an exception with its class, its message and the message of every previous exception
 * (the log line formatter appends those, so a leak through them counts).
 */
function saveArticleFlatten(mixed $value): mixed
{
    if ($value instanceof Throwable) {
        $parts = [];

        for ($e = $value; $e !== null; $e = $e->getPrevious()) {
            $parts[] = $e::class.': '.$e->getMessage();
        }

        return $parts;
    }

    if (is_array($value)) {
        return array_map('saveArticleFlatten', $value);
    }

    if (is_scalar($value) || $value === null) {
        return $value;
    }

    return is_object($value) ? $value::class : gettype($value);
}

/**
 * Starts the live capture of every MessageLogged event (dispatched for every log write on every channel, the exception
 * handler's reports included) and proves with one probe entry that the capture works, so no assertion on it passes
 * vacuously. The probe is dropped afterwards.
 */
function saveArticleCapturedLogs(): ArrayObject
{
    $logs = saveArticleLogs();
    $logs->exchangeArray([]);
    saveArticleWatched()->exchangeArray([]);

    Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
        $logs->append([
            'message' => (string) $event->message,
            'context' => (string) json_encode(saveArticleFlatten($event->context), JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    });

    Log::info('saveArticle capture probe');

    expect(array_column($logs->getArrayCopy(), 'message'))->toContain('saveArticle capture probe');

    $logs->exchangeArray([]);

    return $logs;
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->logs = saveArticleCapturedLogs();
    $this->save = app(SaveArticle::class);
});

afterEach(function (): void {
    foreach (saveArticleWatched() as $text) {
        foreach (saveArticleLogs() as $entry) {
            expect($entry['message'])->not->toContain($text)
                ->and($entry['context'])->not->toContain($text);
        }
    }
});

it('creates a general article as version 1 with its normalised body and version row', function (): void {
    [$title, $body] = saveArticleText('create');
    saveArticleWatch($title, $body);

    $result = $this->save->create($this->admin, ArticleInput::from("  {$title}  ", "{$body}  \r\nSecond line\r\n\r\n", false));

    $article = saveArticleRow($result->article->id);
    $versions = saveArticleVersions($article->id);

    expect($result->outcome)->toBe(SaveOutcome::Created)
        ->and($result->markedSensitive)->toBeFalse()
        ->and($article->kind)->toBe(ArticleKind::General)
        ->and($article->version_no)->toBe(1)
        ->and($article->title)->toBe($title)
        ->and($article->body_md)->toBe("{$body}\nSecond line")
        ->and($article->sensitive)->toBeFalse()
        ->and($article->edited_at)->not->toBeNull()
        ->and($versions)->toHaveCount(1)
        ->and($versions[0]->version_no)->toBe(1)
        ->and($versions[0]->title)->toBe($title)
        ->and($versions[0]->body_md)->toBe($article->body_md)
        ->and($versions[0]->author_id)->toBe($this->admin->id);
});

it('raises the version by one on a body change and keeps every earlier snapshot', function (): void {
    [$title, $body] = saveArticleText('first');
    [, $newBody] = saveArticleText('second');
    saveArticleWatch($title, $body, $newBody);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $body, false))->article;
    $before = saveArticleRow($article->id);

    $this->travel(10)->minutes();

    $result = $this->save->update($this->admin, $article, ArticleInput::from($title, $newBody, false), 1);

    $after = saveArticleRow($article->id);
    $versions = saveArticleVersions($article->id);

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and($result->article->version_no)->toBe(2)
        ->and($after->version_no)->toBe(2)
        ->and($after->body_md)->toBe($newBody)
        ->and($after->edited_at->greaterThan($before->edited_at))->toBeTrue()
        ->and($versions)->toHaveCount(2)
        ->and($versions[0]->version_no)->toBe(1)
        ->and($versions[0]->body_md)->toBe($body)
        ->and($versions[1]->version_no)->toBe(2)
        ->and($versions[1]->title)->toBe($title)
        ->and($versions[1]->body_md)->toBe($newBody)
        ->and($versions[1]->author_id)->toBe($this->admin->id);
});

it('treats a change of the title alone as a content change', function (): void {
    [$title, $body] = saveArticleText('titleonly');
    $newTitle = Canary::canary('renamed_title');
    saveArticleWatch($title, $body, $newTitle);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $body, false))->article;
    $this->save->update($this->admin, $article, ArticleInput::from($title, "{$body} more", false), 1);

    $result = $this->save->update($this->admin, $article, ArticleInput::from($newTitle, "{$body} more", false), 2);

    $versions = saveArticleVersions($article->id);

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and(saveArticleRow($article->id)->version_no)->toBe(3)
        ->and($versions)->toHaveCount(3)
        ->and($versions[2]->title)->toBe($newTitle)
        ->and($versions[2]->body_md)->toBe("{$body} more");
});

it('writes no version and does not move the edit time for a resave that differs only in prose whitespace', function (): void {
    [$title] = saveArticleText('noop');
    $body = "Intro line example\n\n```\ncode line\n```\n\nOutro line example";
    $resave = "Intro line example  \r\n\r\n```\ncode line\n```\n\nOutro line example  \r\n\r\n\r\n";
    saveArticleWatch($title, $body);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $body, false))->article;
    $before = saveArticleRow($article->id);
    $countsBefore = saveArticleCounts();

    $this->travel(10)->minutes();

    $result = $this->save->update($this->admin, $article, ArticleInput::from($title, $resave, false), 1);

    $after = saveArticleRow($article->id);

    expect($result->outcome)->toBe(SaveOutcome::Unchanged)
        ->and($after->version_no)->toBe(1)
        ->and($after->edited_at->equalTo($before->edited_at))->toBeTrue()
        ->and($after->body_md)->toBe($body)
        ->and(saveArticleVersions($article->id))->toHaveCount(1)
        ->and(saveArticleCounts())->toBe($countsBefore);
});

it('stores code bytes as typed and sees a change inside a code block as a content change', function (): void {
    [$title] = saveArticleText('code');
    $typed = "Intro line example  \r\n\r\n```php\r\ncode line  \r\n```\r\n\r\nOutro line example  \r\n";
    $stored = "Intro line example\n\n```php\r\ncode line  \r\n```\r\n\nOutro line example";
    $lf = "Intro line example\n\n```php\ncode line  \n```\n\nOutro line example";
    saveArticleWatch($title, $stored, $lf);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $typed, false))->article;

    expect(saveArticleRow($article->id)->body_md)->toBe($stored);

    $result = $this->save->update($this->admin, $article, ArticleInput::from($title, $lf, false), 1);

    $versions = saveArticleVersions($article->id);

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and(saveArticleRow($article->id)->version_no)->toBe(2)
        ->and($versions)->toHaveCount(2)
        ->and($versions[1]->body_md)->toBe($lf);
});

it('refuses a stale save and writes nothing', function (): void {
    [$title, $body] = saveArticleText('stale');
    [, $theirs] = saveArticleText('theirs');
    [, $mine] = saveArticleText('mine');
    saveArticleWatch($title, $body, $theirs, $mine);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $body, false))->article;
    $this->save->update($this->admin, $article, ArticleInput::from($title, $theirs, false), 1);
    $counts = saveArticleCounts();

    $caught = null;

    try {
        $this->save->update($this->admin, $article, ArticleInput::from($title, $mine, false), 1);
    } catch (StaleArticle $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(StaleArticle::class)
        ->and($caught->currentVersion)->toBe(2)
        ->and($caught->getMessage())->not->toContain($mine)
        ->and(saveArticleRow($article->id)->body_md)->toBe($theirs)
        ->and(saveArticleRow($article->id)->version_no)->toBe(2)
        ->and(saveArticleCounts())->toBe($counts);
});

it('cannot update an archived article', function (): void {
    [$title, $body] = saveArticleText('archived');
    saveArticleWatch($title, $body);

    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article']);
    $counts = saveArticleCounts();

    expect(fn () => $this->save->update($this->admin, $archived, ArticleInput::from($title, $body, false), 1))
        ->toThrow(ModelNotFoundException::class)
        ->and(saveArticleRow($archived->id)->title)->toBe('Example archived article')
        ->and(saveArticleCounts())->toBe($counts);
});

it('refuses a Partner and a user without a role on create and update and writes nothing', function (): void {
    [$title, $body] = saveArticleText('authorise');
    saveArticleWatch($title, $body);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $body, false))->article;
    $counts = saveArticleCounts();

    $actors = [
        'partner' => Canary::partnerFor(Canary::twoClients()[0]),
        'roleless' => Canary::userWithoutRole(null),
        'fresh' => new User,
    ];

    foreach ($actors as $name => $actor) {
        expect(fn () => $this->save->create($actor, ArticleInput::from($title.$name, $body, false)))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => $this->save->update($actor, $article, ArticleInput::from($title.$name, $body.' x', false), 1))
            ->toThrow(AuthorizationException::class);
    }

    expect(saveArticleCounts())->toBe($counts)
        ->and(saveArticleRow($article->id)->version_no)->toBe(1);
});

it('validates the input before any write', function (): void {
    $errors = static function (Closure $callback): array {
        try {
            $callback();
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($errors(static fn () => ArticleInput::from('  ', 'x', false)))->toBe(['title'])
        ->and($errors(static fn () => ArticleInput::from(null, 'x', false)))->toBe(['title'])
        ->and($errors(static fn () => ArticleInput::from(str_repeat('a', KnowledgeArticle::MAX_TITLE_LENGTH + 1), 'x', false)))->toBe(['title'])
        ->and($errors(static fn () => ArticleInput::from(str_repeat('a', KnowledgeArticle::MAX_TITLE_LENGTH), 'x', false)))->toBe([])
        ->and($errors(static fn () => ArticleInput::from('Example title', str_repeat('a', KnowledgeArticle::MAX_BODY_LENGTH + 1), false)))->toBe(['body'])
        ->and($errors(static fn () => ArticleInput::from('Example title', str_repeat('a', KnowledgeArticle::MAX_BODY_LENGTH), false)))->toBe([])
        ->and(ArticleInput::from('Example title', null, false)->body)->toBe('')
        ->and(KnowledgeArticle::MAX_BODY_LENGTH)->toBe(KnowledgeMarkdown::MAX_INPUT_LENGTH)
        ->and(KnowledgeArticle::MAX_BODY_LENGTH)->toBe(100000)
        ->and(ArticleInput::from('Example title', 'x', false)->clearSensitive)->toBeFalse();
});

it('refuses to mark and to clear the sensitive flag in one input', function (): void {
    [$title, $body] = saveArticleText('both');
    saveArticleWatch($title, $body);

    $caught = null;

    try {
        ArticleInput::from($title, $body, true, true);
    } catch (InvalidArgumentException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($caught->getMessage())->toBe('Marking and clearing the sensitive flag cannot be requested together.')
        ->and($caught->getMessage())->not->toContain($title)
        ->and($caught->getMessage())->not->toContain($body);
});

it('logs only allowlisted metadata to the activity log, never the title or the body', function (): void {
    [$title, $body] = saveArticleText('activity');
    [$newTitle, $newBody] = saveArticleText('activity_new');
    saveArticleWatch($title, $body, $newTitle, $newBody);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $body, false))->article;
    $this->save->update($this->admin, $article, ArticleInput::from($newTitle, $newBody, false), 1);

    $rows = saveArticleActivity($article->id);
    $events = array_map(static fn (Activity $row): ?string => $row->event, $rows);

    expect($events)->toContain('created')->toContain('updated');

    foreach ($rows as $row) {
        $stored = (string) json_encode($row->getAttributes());

        foreach ([$title, $body, $newTitle, $newBody] as $text) {
            expect($stored)->not->toContain($text);
        }
    }
});

it('surfaces a failing database write only as a fixed message with the SQLSTATE and keeps the content out of the log', function (): void {
    [$title, $body] = saveArticleText('failing');
    [$conflictTitle, $conflictBody] = saveArticleText('conflict');
    [$newTitle, $newBody] = saveArticleText('failing_new');
    saveArticleWatch($title, $body, $conflictTitle, $conflictBody, $newTitle, $newBody);

    $article = $this->save->create($this->admin, ArticleInput::from($title, $body, false))->article;

    // A conflicting version row at the next number: the version insert of the save hits the unique constraint.
    DB::table('knowledge_article_versions')->insert([
        'article_id' => $article->id,
        'version_no' => 2,
        'title' => $conflictTitle,
        'body_md' => $conflictBody,
        'author_id' => $this->admin->id,
    ]);

    $caught = null;

    try {
        $this->save->update($this->admin, $article, ArticleInput::from($newTitle, $newBody, false), 1);
    } catch (Throwable $e) {
        $caught = $e;
    }

    $row = saveArticleRow($article->id);

    expect($caught)->toBeInstanceOf(ArticleWriteFailed::class)
        ->and($caught->sqlState)->toBe('23505')
        ->and($caught->getPrevious())->toBeNull()
        ->and($caught->getMessage())->toBe('The knowledge article could not be written (SQLSTATE 23505).')
        ->and($caught->getMessage())->not->toContain($newTitle)
        ->and($caught->getMessage())->not->toContain($newBody)
        ->and($row->version_no)->toBe(1)
        ->and($row->title)->toBe($title)
        ->and($row->body_md)->toBe($body);

    // The default exception handler logs it: the reporter path is observed, and it carries no content.
    report($caught);

    expect(array_column($this->logs->getArrayCopy(), 'message'))->toContain($caught->getMessage());
});
