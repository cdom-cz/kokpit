<?php

declare(strict_types=1);

use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\SecretFixtures;

/*
 * The two stale tokens and the restore marker of SaveArticle::update() (D-13, D-11, VER-03): an editor opened before a
 * redaction of the current version cannot save its text back, and a save made on behalf of a restore is recorded as
 * restored from its source version. Every text is fictional. The redaction of 19-09 does not exist yet in this plan, so
 * the tests raise `redaction_no` with a raw update, which is the only thing that Action does to the article row.
 *
 * Every test runs under a live capture of every log write (saveArticleTokensCapturedLogs); the afterEach hook asserts
 * that no captured entry holds any text a test put on its watch list.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function saveArticleTokensSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article row, archived ones included.
 */
function saveArticleTokensRow(string $id): KnowledgeArticle
{
    return saveArticleTokensSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The version rows of an article, oldest first.
 *
 * @return list<KnowledgeArticleVersion>
 */
function saveArticleTokensVersions(string $id): array
{
    return saveArticleTokensSystem(static fn (): array => KnowledgeArticleVersion::query()
        ->where('article_id', $id)
        ->orderBy('version_no')
        ->get()
        ->all());
}

/**
 * Every activity row of the article, oldest first.
 *
 * @return list<Activity>
 */
function saveArticleTokensActivity(string $id): array
{
    return saveArticleTokensSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * Every activity row of the article as one JSON text, to search for content that must never be there.
 */
function saveArticleTokensActivityJson(string $id): string
{
    return (string) json_encode(array_map(static fn (Activity $activity): array => $activity->toArray(), saveArticleTokensActivity($id)));
}

/**
 * What the redaction of 19-09 does to the article row: it raises the redaction number (D-13). The version number does
 * not move.
 */
function saveArticleTokensBumpRedaction(string $id): void
{
    DB::table('knowledge_articles')->where('id', $id)->increment('redaction_no');
}

/**
 * The captured log entries (message and context, both flattened to text).
 */
function saveArticleTokensLogs(): ArrayObject
{
    static $logs = null;

    return $logs ??= new ArrayObject;
}

/**
 * The texts that must appear in no captured log entry.
 */
function saveArticleTokensWatched(): ArrayObject
{
    static $watched = null;

    return $watched ??= new ArrayObject;
}

/**
 * Puts texts on the watch list of the running test.
 */
function saveArticleTokensWatch(string ...$texts): void
{
    foreach ($texts as $text) {
        saveArticleTokensWatched()->append($text);
    }
}

/**
 * A context value as text, an exception with its class, its message and the message of every previous exception.
 */
function saveArticleTokensFlatten(mixed $value): mixed
{
    if ($value instanceof Throwable) {
        $parts = [];

        for ($e = $value; $e !== null; $e = $e->getPrevious()) {
            $parts[] = $e::class.': '.$e->getMessage();
        }

        return $parts;
    }

    if (is_array($value)) {
        return array_map('saveArticleTokensFlatten', $value);
    }

    if (is_scalar($value) || $value === null) {
        return $value;
    }

    return is_object($value) ? $value::class : gettype($value);
}

/**
 * Starts the live capture of every MessageLogged event and proves with one probe entry that the capture works.
 */
function saveArticleTokensCapturedLogs(): ArrayObject
{
    $logs = saveArticleTokensLogs();
    $logs->exchangeArray([]);
    saveArticleTokensWatched()->exchangeArray([]);

    Event::listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
        $logs->append([
            'message' => (string) $event->message,
            'context' => (string) json_encode(saveArticleTokensFlatten($event->context), JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    });

    Log::info('saveArticleTokens capture probe');

    expect(array_column($logs->getArrayCopy(), 'message'))->toContain('saveArticleTokens capture probe');

    $logs->exchangeArray([]);

    return $logs;
}

/**
 * The notifications waiting for the browser, pulled the way Filament's own assertion pulls them.
 *
 * @return list<Notification>
 */
function saveArticleTokensNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotifications();

    return array_values($component->notifications->all());
}

/**
 * Runs the callback and returns the StaleArticle it throws, or fails the test when it throws nothing else.
 *
 * @param  Closure(): mixed  $callback
 */
function saveArticleTokensStale(Closure $callback): StaleArticle
{
    try {
        $callback();
    } catch (StaleArticle $stale) {
        return $stale;
    }

    throw new RuntimeException('Expected a StaleArticle.');
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->logs = saveArticleTokensCapturedLogs();
    $this->save = app(SaveArticle::class);
});

afterEach(function (): void {
    foreach (saveArticleTokensWatched() as $text) {
        foreach (saveArticleTokensLogs() as $entry) {
            expect($entry['message'])->not->toContain($text)
                ->and($entry['context'])->not->toContain($text);
        }
    }
});

it('refuses a save from an editor opened before a redaction of the current version, and writes nothing', function (): void {
    $title = Canary::canary('tokens_title');
    $typed = 'Example typed text '.Canary::canary('tokens_typed');
    saveArticleTokensWatch($title, $typed);

    $article = KnowledgeFixtures::article(['title' => $title, 'body_md' => 'Example stored text']);
    saveArticleTokensBumpRedaction($article->getKey());

    $before = saveArticleTokensRow($article->getKey());
    $activityBefore = count(saveArticleTokensActivity($article->getKey()));

    $stale = saveArticleTokensStale(fn () => $this->save->update($this->admin, $article, ArticleInput::from($title, $typed, false), 1, 0));

    $after = saveArticleTokensRow($article->getKey());

    expect($stale->redactionChanged)->toBeTrue()
        ->and($stale->currentVersion)->toBe(1)
        ->and($stale->getMessage())->not->toContain($title)
        ->and($stale->getMessage())->not->toContain($typed)
        ->and($after->title)->toBe($before->title)
        ->and($after->body_md)->toBe($before->body_md)
        ->and($after->version_no)->toBe(1)
        ->and($after->redaction_no)->toBe(1)
        ->and(saveArticleTokensVersions($article->getKey()))->toHaveCount(1)
        ->and(saveArticleTokensActivity($article->getKey()))->toHaveCount($activityBefore);
});

it('saves normally with the redaction number the article has now', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text']);
    saveArticleTokensBumpRedaction($article->getKey());

    $result = $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example new text', false), 1, 1);

    expect($result->article->version_no)->toBe(2)
        ->and(saveArticleTokensRow($article->getKey())->body_md)->toBe('Example new text');
});

it('reports the stale version first when both numbers are wrong', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example article', 'body' => 'Example text one'],
        ['title' => 'Example article', 'body' => 'Example text two'],
    ]);
    saveArticleTokensBumpRedaction($article->getKey());

    $stale = saveArticleTokensStale(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example text three', false), 1, 0));

    expect($stale->redactionChanged)->toBeFalse()
        ->and($stale->currentVersion)->toBe(2)
        ->and(saveArticleTokensRow($article->getKey())->version_no)->toBe(2)
        ->and(saveArticleTokensVersions($article->getKey()))->toHaveCount(2);
});

it('refuses the save of an edit screen opened before a redaction with a danger notice and keeps the typed text', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text']);

    $page = Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->assertSet('loadedRedactionNo', 0);

    saveArticleTokensBumpRedaction($article->getKey());

    $page->fillForm(['body' => 'Example typed text'])
        ->call('save')
        ->assertNoRedirect()
        ->assertSet('data.body', 'Example typed text');

    $notifications = saveArticleTokensNotifications();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]->getTitle())->toBe(__('knowledge.notifications.stale_redacted_title'))
        ->and($notifications[0]->getBody())->toBe(__('knowledge.notifications.stale_redacted_body'))
        ->and($notifications[0]->getStatus())->toBe('danger')
        ->and($notifications[0]->getDuration())->toBe('persistent')
        ->and(array_values($notifications[0]->getActions())[0]->getName())->toBe('load_current');

    expect(saveArticleTokensRow($article->getKey())->body_md)->toBe('Example stored text')
        ->and(saveArticleTokensRow($article->getKey())->version_no)->toBe(1)
        ->and(saveArticleTokensVersions($article->getKey()))->toHaveCount(1);

    // The locked number never refreshes: the same save is refused again.
    $page->call('save')->assertNoRedirect()->assertSet('data.body', 'Example typed text');

    expect(saveArticleTokensNotifications())->toHaveCount(1)
        ->and(saveArticleTokensRow($article->getKey())->version_no)->toBe(1);
});

it('lets an edit screen opened after the redaction save normally', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text']);
    saveArticleTokensBumpRedaction($article->getKey());

    Livewire::test(EditKnowledgeArticle::class, ['record' => $article->getRouteKey()])
        ->assertSet('loadedRedactionNo', 1)
        ->fillForm(['body' => 'Example typed text'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(saveArticleTokensRow($article->getKey())->version_no)->toBe(2)
        ->and(saveArticleTokensRow($article->getKey())->body_md)->toBe('Example typed text');
});

it('records a restore as a new version marked with its source and logs only the two numbers', function (): void {
    $first = 'Example first title '.Canary::canary('restore_a');
    $firstBody = 'Example first body '.Canary::canary('restore_b');
    saveArticleTokensWatch($first, $firstBody);

    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => $first, 'body' => $firstBody],
        ['title' => 'Example second title', 'body' => 'Example second body'],
        ['title' => 'Example third title', 'body' => 'Example third body'],
    ]);

    $result = $this->save->update($this->admin, $article, ArticleInput::from($first, $firstBody, false), 3, 0, restoredFromVersionNo: 1);

    $versions = saveArticleTokensVersions($article->getKey());
    $rows = array_values(array_filter(
        saveArticleTokensActivity($article->getKey()),
        static fn (Activity $activity): bool => $activity->event === 'version_restored',
    ));

    expect($result->article->version_no)->toBe(4)
        ->and($versions)->toHaveCount(4)
        ->and($versions[3]->restored_from_version_no)->toBe(1)
        ->and($versions[3]->title)->toBe($first)
        ->and($versions[3]->body_md)->toBe($firstBody)
        ->and($versions[0]->restored_from_version_no)->toBeNull()
        ->and($versions[1]->restored_from_version_no)->toBeNull()
        ->and($versions[2]->restored_from_version_no)->toBeNull()
        ->and($versions[2]->title)->toBe('Example third title')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->causer_id)->toBe($this->admin->id)
        ->and($rows[0]->properties->toArray())->toBe(['from_version_no' => 1, 'version_no' => 4])
        ->and(saveArticleTokensActivityJson($article->getKey()))->not->toContain($firstBody);
});

it('writes neither a version nor a restore entry when the restored text equals the current text', function (): void {
    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => 'Example title', 'body' => 'Example body'],
        ['title' => 'Example title', 'body' => 'Example body'],
    ]);

    $result = $this->save->update($this->admin, $article, ArticleInput::from('Example title', 'Example body', false), 2, 0, restoredFromVersionNo: 1);

    $restores = array_filter(
        saveArticleTokensActivity($article->getKey()),
        static fn (Activity $activity): bool => $activity->event === 'version_restored',
    );

    expect($result->outcome)->toBe(SaveOutcome::Unchanged)
        ->and(saveArticleTokensVersions($article->getKey()))->toHaveCount(2)
        ->and($restores)->toBe([]);
});

it('leaves the restore marker null for every other save', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example title', 'body_md' => 'Example body']);

    $this->save->update($this->admin, $article, ArticleInput::from('Example title', 'Example new body', false), 1, 0);

    $versions = saveArticleTokensVersions($article->getKey());
    $restores = array_filter(
        saveArticleTokensActivity($article->getKey()),
        static fn (Activity $activity): bool => $activity->event === 'version_restored',
    );

    expect($versions)->toHaveCount(2)
        ->and($versions[1]->restored_from_version_no)->toBeNull()
        ->and($restores)->toBe([]);
});

it('writes the acknowledgment and the restore entry of an ambiguous restore without any content', function (): void {
    $secret = SecretFixtures::passwordAssignment();
    $body = SecretFixtures::bodyWith($secret, 2);
    $title = Canary::canary('restore_ambiguous');
    saveArticleTokensWatch($title, $body, $secret, ...SecretFixtures::needles($secret));

    $article = KnowledgeFixtures::articleWithVersions([
        ['title' => $title, 'body' => $body],
        ['title' => 'Example clean title', 'body' => 'Example clean body'],
    ]);

    $result = $this->save->update($this->admin, $article, ArticleInput::from($title, $body, false), 2, 0, acknowledge: true, restoredFromVersionNo: 1);

    $events = array_map(static fn (Activity $activity): string => (string) $activity->event, saveArticleTokensActivity($article->getKey()));
    $json = saveArticleTokensActivityJson($article->getKey());

    expect($result->markedSensitive)->toBeTrue()
        ->and($events)->toContain('secret_acknowledged')
        ->and($events)->toContain('version_restored')
        ->and($json)->not->toContain($title)
        ->and($json)->not->toContain($secret);

    foreach (SecretFixtures::needles($secret) as $needle) {
        expect($json)->not->toContain($needle);
    }
});
