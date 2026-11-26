<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\RestoreVersion;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Exceptions\SecretGuardRefused;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\SecretFixtures;

/*
 * Restoring an earlier version as a new version (Phase 19, plan 19-09; VER-03, D-11, SEC-01 restore clause): the one
 * save path, the stale check, the secret guard again, the archived and missing cases and the content-free logging.
 * Every text is fictional; secret-shaped samples come only from SecretFixtures.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function restoreVersionSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article row, archived ones included.
 */
function restoreVersionArticle(string $id): KnowledgeArticle
{
    return restoreVersionSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version rows of an article, oldest first.
 *
 * @return list<KnowledgeArticleVersion>
 */
function restoreVersionRows(string $id): array
{
    return restoreVersionSystem(static fn (): array => KnowledgeArticleVersion::query()
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
function restoreVersionActivity(string $id, string $event): array
{
    return restoreVersionSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * Every activity row of the article as one JSON text.
 */
function restoreVersionActivityJson(string $id): string
{
    return (string) json_encode(restoreVersionSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->get()
        ->map(static fn (Activity $row): array => $row->getAttributes())
        ->all()));
}

/**
 * The number of version and activity rows, to prove that a refused restore wrote nothing.
 *
 * @return array{versions: int, activity: int}
 */
function restoreVersionCounts(): array
{
    return restoreVersionSystem(static fn (): array => [
        'versions' => KnowledgeArticleVersion::query()->count(),
        'activity' => Activity::query()->count(),
    ]);
}

/**
 * A three-version article: version 1 and 2 are plain, version 3 is the current one.
 */
function restoreVersionThree(): KnowledgeArticle
{
    return KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example first title', 'body' => "Example line one\nExample line two"],
        ['title' => 'Example second title', 'body' => 'Example second body'],
        ['title' => 'Example third title', 'body' => 'Example third body'],
    ]);
}

/**
 * Starts the live capture of every log write and proves it with a probe entry.
 */
function restoreVersionCapturedLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
        $logs->append((string) $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
    });

    Log::info('restoreVersion capture probe');

    expect(implode("\n", $logs->getArrayCopy()))->toContain('restoreVersion capture probe');

    $logs->exchangeArray([]);

    return $logs;
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->restore = app(RestoreVersion::class);
    $this->logs = restoreVersionCapturedLogs();
});

it('restores version 1 as version 4 and keeps versions 1 to 3 untouched', function (): void {
    $article = restoreVersionThree();
    $before = restoreVersionRows($article->id);

    $result = $this->restore->handle($this->admin, $article, 1, 3, 0);

    $versions = restoreVersionRows($article->id);
    $row = restoreVersionArticle($article->id);
    $markers = restoreVersionActivity($article->id, 'version_restored');

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and($versions)->toHaveCount(4)
        ->and($versions[3]->version_no)->toBe(4)
        ->and($versions[3]->title)->toBe('Example first title')
        ->and($versions[3]->body_md)->toBe("Example line one\nExample line two")
        ->and($versions[3]->restored_from_version_no)->toBe(1)
        ->and($versions[3]->author_id)->toBe($this->admin->id)
        ->and($row->version_no)->toBe(4)
        ->and($row->title)->toBe('Example first title')
        ->and($row->body_md)->toBe("Example line one\nExample line two")
        ->and($result->article->version_no)->toBe(4)
        ->and($markers)->toHaveCount(1)
        ->and($markers[0]->properties->toArray())->toBe(['from_version_no' => 1, 'version_no' => 4]);

    foreach ([0, 1, 2] as $index) {
        expect($versions[$index]->title)->toBe($before[$index]->title)
            ->and($versions[$index]->body_md)->toBe($before[$index]->body_md)
            ->and($versions[$index]->restored_from_version_no)->toBe($before[$index]->restored_from_version_no);
    }
});

it('refuses a restore opened on an older version or an older redaction number and writes nothing', function (): void {
    $article = restoreVersionThree();
    $counts = restoreVersionCounts();

    $versionStale = null;
    $redactionStale = null;

    try {
        $this->restore->handle($this->admin, $article, 1, 2, 0);
    } catch (StaleArticle $e) {
        $versionStale = $e;
    }

    try {
        $this->restore->handle($this->admin, $article, 1, 3, 1);
    } catch (StaleArticle $e) {
        $redactionStale = $e;
    }

    expect($versionStale)->toBeInstanceOf(StaleArticle::class)
        ->and($versionStale->currentVersion)->toBe(3)
        ->and($versionStale->redactionChanged)->toBeFalse()
        ->and($redactionStale)->toBeInstanceOf(StaleArticle::class)
        ->and($redactionStale->redactionChanged)->toBeTrue()
        ->and(restoreVersionCounts())->toBe($counts)
        ->and(restoreVersionArticle($article->id)->version_no)->toBe(3);
});

it('refuses a version with a blocking secret even with the acknowledgment and writes nothing', function (): void {
    $secret = SecretFixtures::githubToken();
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example old title', 'body' => SecretFixtures::bodyWith($secret, 2)],
        ['title' => 'Example new title', 'body' => 'Example clean body'],
    ]);
    $counts = restoreVersionCounts();

    $refused = null;

    try {
        $this->restore->handle($this->admin, $article, 1, 2, 0, true);
    } catch (SecretGuardRefused $e) {
        $refused = $e;
    }

    expect($refused)->toBeInstanceOf(SecretGuardRefused::class)
        ->and($refused->hasBlocking())->toBeTrue()
        ->and(restoreVersionCounts())->toBe($counts)
        ->and(restoreVersionArticle($article->id)->version_no)->toBe(2)
        ->and(restoreVersionArticle($article->id)->body_md)->toBe('Example clean body');

    foreach (SecretFixtures::needles($secret) as $needle) {
        expect($refused->getMessage())->not->toContain($needle)
            ->and(restoreVersionActivityJson($article->id))->not->toContain($needle);
    }
});

it('restores a version with an ambiguous finding only with the acknowledgment, and marks the article sensitive', function (): void {
    $secret = SecretFixtures::passwordAssignment();
    $body = SecretFixtures::bodyWith($secret, 2);
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example old title', 'body' => $body],
        ['title' => 'Example new title', 'body' => 'Example clean body'],
    ]);
    $counts = restoreVersionCounts();

    $refused = null;

    try {
        $this->restore->handle($this->admin, $article, 1, 2, 0);
    } catch (SecretGuardRefused $e) {
        $refused = $e;
    }

    expect($refused)->toBeInstanceOf(SecretGuardRefused::class)
        ->and($refused->isAcknowledgeable())->toBeTrue()
        ->and(restoreVersionCounts())->toBe($counts);

    $result = $this->restore->handle($this->admin, $article, 1, 2, 0, true);

    $row = restoreVersionArticle($article->id);
    $acknowledged = restoreVersionActivity($article->id, 'secret_acknowledged');
    $markers = restoreVersionActivity($article->id, 'version_restored');

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and($result->markedSensitive)->toBeTrue()
        ->and($row->sensitive)->toBeTrue()
        ->and($row->version_no)->toBe(3)
        ->and($acknowledged)->toHaveCount(1)
        ->and($acknowledged[0]->properties->toArray())->toBe(['rules' => ['password_assignment'], 'version_no' => 3])
        ->and($markers)->toHaveCount(1);

    foreach (SecretFixtures::needles($secret) as $needle) {
        expect(restoreVersionActivityJson($article->id))->not->toContain($needle);
    }
});

it('writes nothing when the restored text equals the current text', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example same title', 'body' => 'Example same body'],
        ['title' => 'Example other title', 'body' => 'Example other body'],
        ['title' => 'Example same title', 'body' => 'Example same body'],
    ]);
    $counts = restoreVersionCounts();

    $result = $this->restore->handle($this->admin, $article, 1, 3, 0);

    expect($result->outcome)->toBe(SaveOutcome::Unchanged)
        ->and(restoreVersionCounts())->toBe($counts)
        ->and(restoreVersionArticle($article->id)->version_no)->toBe(3);
});

it('cannot restore a version of an archived article or a version that does not exist', function (): void {
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article', 'version_no' => 2]);
    $article = restoreVersionThree();
    $counts = restoreVersionCounts();

    expect(fn () => $this->restore->handle($this->admin, $archived, 1, 2, 0))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->restore->handle($this->admin, $article, 9, 3, 0))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->restore->handle($this->admin, $article, 0, 3, 0))
        ->toThrow(ModelNotFoundException::class)
        ->and(restoreVersionCounts())->toBe($counts);
});

it('keeps the folder, client, project and tags of the article', function (): void {
    $folder = KnowledgeFixtures::folder('Example restore folder');
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example old title', 'body' => 'Example old body'],
        ['title' => 'Example current title', 'body' => 'Example current body'],
    ], ['folder_id' => $folder->id]);

    restoreVersionSystem(static function () use ($article): void {
        $article->attachTags(['example-tag'], TagType::Knowledge->value);
    });

    $this->restore->handle($this->admin, $article, 1, 2, 0);

    $row = restoreVersionArticle($article->id);

    expect($row->folder_id)->toBe($folder->id)
        ->and($row->version_no)->toBe(3)
        ->and($row->title)->toBe('Example old title')
        ->and(restoreVersionSystem(static fn (): array => $row->tagsWithType(TagType::Knowledge->value)->pluck('name')->all()))->toBe(['example-tag']);
});

it('refuses a Partner and a user without a role', function (): void {
    $article = restoreVersionThree();
    $counts = restoreVersionCounts();

    $actors = [
        'partner' => Canary::partnerFor(Canary::twoClients()[0]),
        'roleless' => Canary::userWithoutRole(null),
    ];

    foreach ($actors as $actor) {
        expect(fn () => $this->restore->handle($actor, $article, 1, 3, 0))->toThrow(AuthorizationException::class);
    }

    expect(restoreVersionCounts())->toBe($counts)
        ->and(restoreVersionArticle($article->id)->version_no)->toBe(3);
});

it('puts neither the title, the body nor a secret needle into a message, an activity row or a log line', function (): void {
    $secret = SecretFixtures::passwordAssignment();
    $title = Canary::canary('restore_title');
    $body = 'Example body line. '.str_replace('_', ' ', Canary::canary('restore_body'))."\n".$secret;
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => $title, 'body' => $body],
        ['title' => 'Example new title', 'body' => 'Example clean body'],
    ]);

    $message = '';

    try {
        $this->restore->handle($this->admin, $article, 1, 2, 0);
    } catch (SecretGuardRefused $e) {
        $message = $e->getMessage();
    }

    $this->restore->handle($this->admin, $article, 1, 2, 0, true);

    $watched = [$title, $body, ...SecretFixtures::needles($secret)];
    $activity = restoreVersionActivityJson($article->id);
    $logs = implode("\n", $this->logs->getArrayCopy());

    expect($message)->not->toBe('');

    foreach ($watched as $text) {
        expect($message)->not->toContain($text)
            ->and($activity)->not->toContain($text)
            ->and($logs)->not->toContain($text);
    }
});
