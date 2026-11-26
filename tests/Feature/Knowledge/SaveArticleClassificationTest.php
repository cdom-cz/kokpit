<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Knowledge\Actions\ArticleClassification;
use App\Domain\Knowledge\Actions\ArticleInput;
use App\Domain\Knowledge\Actions\SaveArticle;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Exceptions\SecretGuardRefused;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;
use Tests\Support\SecretFixtures;

/*
 * The classification written through the one save path (ORG-03, ORG-04, D-04, D-06): folder, client, project and
 * knowledge tags are saved inside the locked transaction, together with the content or alone, and never by a refused
 * save. Every name is fictional.
 */

/**
 * Runs the callback without the Partner scope, to read what the tests assert on.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function saveArticleClassificationSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The stored article row, archived ones included.
 */
function saveArticleClassificationRow(string $id): KnowledgeArticle
{
    return saveArticleClassificationSystem(static fn (): KnowledgeArticle => KnowledgeArticle::query()->withTrashed()->findOrFail($id));
}

/**
 * The number of version rows of an article.
 */
function saveArticleClassificationVersionCount(string $id): int
{
    return saveArticleClassificationSystem(static fn (): int => KnowledgeArticleVersion::query()->where('article_id', $id)->count());
}

/**
 * The names of the tags of one type on an article, sorted.
 *
 * @return list<string>
 */
function saveArticleClassificationTags(string $id, string $type = 'knowledge'): array
{
    return saveArticleClassificationSystem(static function () use ($id, $type): array {
        $names = KnowledgeArticle::query()->withTrashed()->findOrFail($id)->tagsWithType($type)->map(static fn (Tag $tag): string => (string) $tag->name)->all();
        sort($names);

        return array_values($names);
    });
}

/**
 * The number of knowledge tag rows.
 */
function saveArticleClassificationTagRows(): int
{
    return saveArticleClassificationSystem(static fn (): int => Tag::query()->where('type', TagType::Knowledge->value)->count());
}

/**
 * Every activity row of the article as one JSON text.
 */
function saveArticleClassificationActivityJson(string $id): string
{
    return (string) json_encode(saveArticleClassificationSystem(static fn (): array => Activity::query()
        ->where('subject_id', $id)
        ->get()
        ->map(static fn (Activity $activity): array => $activity->toArray())
        ->all()));
}

/**
 * The three link columns of an article, as stored.
 *
 * @return array{folder_id: string|null, client_id: string|null, project_id: string|null}
 */
function saveArticleClassificationLinks(string $id): array
{
    $row = saveArticleClassificationRow($id);

    return ['folder_id' => $row->folder_id, 'client_id' => $row->client_id, 'project_id' => $row->project_id];
}

/**
 * A fictional client with one project, as the ids.
 *
 * @return array{client: string, project: string}
 */
function saveArticleClassificationClientWithProject(): array
{
    return saveArticleClassificationSystem(static function (): array {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->getKey()]);

        return ['client' => (string) $client->getKey(), 'project' => (string) $project->getKey()];
    });
}

/**
 * Runs the callback and returns the ValidationException it throws, or null.
 *
 * @param  Closure(): mixed  $callback
 */
function saveArticleClassificationInvalid(Closure $callback): ?ValidationException
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e;
    }

    return null;
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->save = app(SaveArticle::class);
});

it('builds a classification from raw values: empty ids are null and tags are trimmed and merged', function (): void {
    $folder = (string) Str::uuid();

    $classification = ArticleClassification::from($folder, '', null, ['  Example server ', 'example SERVER', '', 'Example backup']);

    expect($classification->folderId)->toBe($folder)
        ->and($classification->clientId)->toBeNull()
        ->and($classification->projectId)->toBeNull()
        ->and($classification->tags)->toBe(['Example server', 'Example backup']);

    $nothing = ArticleClassification::from(null, 12, [], 'not a list');

    expect($nothing->folderId)->toBeNull()
        ->and($nothing->clientId)->toBeNull()
        ->and($nothing->projectId)->toBeNull()
        ->and($nothing->tags)->toBe([]);
});

it('refuses a malformed id, a project without a client and bad tags as field errors', function (): void {
    $uuid = (string) Str::uuid();

    $folder = saveArticleClassificationInvalid(fn () => ArticleClassification::from('not-a-uuid', null, null, []));
    $client = saveArticleClassificationInvalid(fn () => ArticleClassification::from(null, 'not-a-uuid', null, []));
    $project = saveArticleClassificationInvalid(fn () => ArticleClassification::from(null, $uuid, 'not-a-uuid', []));
    $needsClient = saveArticleClassificationInvalid(fn () => ArticleClassification::from(null, null, $uuid, []));
    $tooLong = saveArticleClassificationInvalid(fn () => ArticleClassification::from(null, null, null, [str_repeat('a', ArticleClassification::MAX_TAG_LENGTH + 1)]));
    $tooMany = saveArticleClassificationInvalid(fn () => ArticleClassification::from(null, null, null, array_map(static fn (int $n): string => 'Example tag '.$n, range(1, ArticleClassification::MAX_TAGS + 1))));
    $nul = saveArticleClassificationInvalid(fn () => ArticleClassification::from(null, null, null, ["Example\0tag"]));

    expect(array_keys($folder?->errors() ?? []))->toBe(['folder_id'])
        ->and(array_keys($client?->errors() ?? []))->toBe(['client_id'])
        ->and(array_keys($project?->errors() ?? []))->toBe(['project_id'])
        ->and($needsClient?->errors()['project_id'][0] ?? null)->toBe(__('knowledge.links.project_needs_client'))
        ->and(array_keys($tooLong?->errors() ?? []))->toBe(['tags'])
        ->and(array_keys($tooMany?->errors() ?? []))->toBe(['tags'])
        ->and(array_keys($nul?->errors() ?? []))->toBe(['tags']);

    // The limit itself is fine.
    $limit = ArticleClassification::from(null, null, null, array_map(static fn (int $n): string => 'Example tag '.$n, range(1, ArticleClassification::MAX_TAGS)));

    expect($limit->tags)->toHaveCount(ArticleClassification::MAX_TAGS);
});

it('saves a classification alone without a version, keeps the edit time and leaves other tag types alone', function (): void {
    $edited = CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC');
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text', 'edited_at' => $edited]);
    saveArticleClassificationSystem(static fn () => $article->attachTags(['Example client tag'], TagType::Client->value));

    $folder = KnowledgeFixtures::folder();
    $owner = saveArticleClassificationClientWithProject();

    $classification = ArticleClassification::from($folder->getKey(), $owner['client'], $owner['project'], ['Example backup', 'Example server']);

    $result = $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: $classification), 1, 0);

    $row = saveArticleClassificationRow($article->getKey());

    expect($result->outcome)->toBe(SaveOutcome::ClassificationChanged)
        ->and($row->version_no)->toBe(1)
        ->and($row->edited_at->equalTo($edited))->toBeTrue()
        ->and(saveArticleClassificationVersionCount($article->getKey()))->toBe(1)
        ->and(saveArticleClassificationLinks($article->getKey()))->toBe(['folder_id' => $folder->getKey(), 'client_id' => $owner['client'], 'project_id' => $owner['project']])
        ->and(saveArticleClassificationTags($article->getKey()))->toBe(['Example backup', 'Example server'])
        ->and(saveArticleClassificationTags($article->getKey(), TagType::Client->value))->toBe(['Example client tag']);

    // The same call again changes nothing.
    $again = $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: $classification), 1, 0);

    expect($again->outcome)->toBe(SaveOutcome::Unchanged);
});

it('writes a classification together with a content change as exactly one new version', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text']);
    $folder = KnowledgeFixtures::folder();

    $classification = ArticleClassification::from($folder->getKey(), null, null, ['Example tag']);

    $result = $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example changed text', false, classification: $classification), 1, 0);

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and(saveArticleClassificationRow($article->getKey())->version_no)->toBe(2)
        ->and(saveArticleClassificationVersionCount($article->getKey()))->toBe(2)
        ->and(saveArticleClassificationLinks($article->getKey())['folder_id'])->toBe($folder->getKey())
        ->and(saveArticleClassificationTags($article->getKey()))->toBe(['Example tag']);
});

it('keeps the stored classification when the input carries none', function (): void {
    $article = KnowledgeFixtures::taggedArticle(['Example tag'], ['title' => 'Example article', 'body_md' => 'Example stored text']);
    $folder = KnowledgeFixtures::folder();

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: ArticleClassification::from($folder->getKey(), null, null, ['Example tag'])), 1, 0);
    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example changed text', false), 1, 0);

    expect(saveArticleClassificationLinks($article->getKey())['folder_id'])->toBe($folder->getKey())
        ->and(saveArticleClassificationTags($article->getKey()))->toBe(['Example tag']);
});

it('changes no id and no tag when the save is stale, refused by the guard or aimed at an archived article', function (): void {
    $article = KnowledgeFixtures::taggedArticle(['Example kept tag'], ['title' => 'Example article', 'body_md' => 'Example stored text']);
    $folder = KnowledgeFixtures::folder();
    $owner = saveArticleClassificationClientWithProject();

    $classification = ArticleClassification::from($folder->getKey(), $owner['client'], $owner['project'], ['Example new tag']);

    $untouched = static function () use ($article): array {
        return [saveArticleClassificationLinks($article->getKey()), saveArticleClassificationTags($article->getKey()), saveArticleClassificationRow($article->getKey())->version_no];
    };

    $expected = $untouched();
    $rows = saveArticleClassificationTagRows();

    // A wrong version.
    expect(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: $classification), 2, 0))->toThrow(StaleArticle::class)
        ->and($untouched())->toBe($expected);

    // A wrong redaction number.
    DB::table('knowledge_articles')->where('id', $article->getKey())->increment('redaction_no');

    expect(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: $classification), 1, 0))->toThrow(StaleArticle::class)
        ->and($untouched())->toBe($expected);

    // A blocking secret and an ambiguous one without acknowledgment.
    foreach ([SecretFixtures::githubToken(), SecretFixtures::passwordAssignment()] as $secret) {
        $body = SecretFixtures::bodyWith($secret, 2);

        expect(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', $body, false, classification: $classification), 1, 1))->toThrow(SecretGuardRefused::class)
            ->and($untouched())->toBe($expected);
    }

    // An archived article.
    saveArticleClassificationSystem(static fn () => $article->delete());

    expect(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: $classification), 1, 1))->toThrow(ModelNotFoundException::class)
        ->and($untouched())->toBe($expected)
        ->and(saveArticleClassificationTagRows())->toBe($rows);
});

it('refuses a project of another client and a folder that does not exist, and writes nothing', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text']);
    $first = saveArticleClassificationClientWithProject();
    $second = saveArticleClassificationClientWithProject();
    $before = saveArticleClassificationLinks($article->getKey());

    $mismatch = saveArticleClassificationInvalid(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: ArticleClassification::from(null, $first['client'], $second['project'], ['Example tag'])), 1, 0));
    $folder = saveArticleClassificationInvalid(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: ArticleClassification::from((string) Str::uuid(), null, null, ['Example tag'])), 1, 0));
    $client = saveArticleClassificationInvalid(fn () => $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: ArticleClassification::from(null, (string) Str::uuid(), null, [])), 1, 0));

    expect(array_keys($mismatch?->errors() ?? []))->toBe(['project_id'])
        ->and(array_keys($folder?->errors() ?? []))->toBe(['folder_id'])
        ->and(array_keys($client?->errors() ?? []))->toBe(['client_id'])
        ->and(saveArticleClassificationLinks($article->getKey()))->toBe($before)
        ->and(saveArticleClassificationTags($article->getKey()))->toBe([])
        ->and(saveArticleClassificationRow($article->getKey())->version_no)->toBe(1);
});

it('keeps a stored link to an archived client and project through a title change', function (): void {
    $owner = saveArticleClassificationClientWithProject();
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text', 'client_id' => $owner['client'], 'project_id' => $owner['project']]);

    saveArticleClassificationSystem(static function () use ($owner): void {
        Project::query()->whereKey($owner['project'])->firstOrFail()->delete();
        Client::query()->whereKey($owner['client'])->firstOrFail()->delete();
    });

    $classification = ArticleClassification::from(null, $owner['client'], $owner['project'], []);

    $result = $this->save->update($this->admin, $article, ArticleInput::from('Example renamed article', 'Example stored text', false, classification: $classification), 1, 0);

    expect($result->outcome)->toBe(SaveOutcome::Updated)
        ->and(saveArticleClassificationLinks($article->getKey()))->toBe(['folder_id' => null, 'client_id' => $owner['client'], 'project_id' => $owner['project']]);
});

it('reuses an existing knowledge tag with its stored spelling', function (): void {
    KnowledgeFixtures::taggedArticle(['Example Server'], ['title' => 'Example other article']);
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text']);
    $rows = saveArticleClassificationTagRows();

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: ArticleClassification::from(null, null, null, ['example server'])), 1, 0);

    expect(saveArticleClassificationTags($article->getKey()))->toBe(['Example Server'])
        ->and(saveArticleClassificationTagRows())->toBe($rows);
});

it('removes the knowledge tags that are no longer in the input and keeps the others', function (): void {
    $article = KnowledgeFixtures::taggedArticle(['Example one', 'Example two'], ['title' => 'Example article', 'body_md' => 'Example stored text']);

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: ArticleClassification::from(null, null, null, ['Example two', 'Example three'])), 1, 0);

    expect(saveArticleClassificationTags($article->getKey()))->toBe(['Example three', 'Example two']);
});

it('creates an article with its classification as version 1', function (): void {
    $folder = KnowledgeFixtures::folder();
    $owner = saveArticleClassificationClientWithProject();

    $result = $this->save->create($this->admin, ArticleInput::from('Example new article', 'Example text', false, classification: ArticleClassification::from($folder->getKey(), $owner['client'], $owner['project'], ['Example tag'])));

    $id = $result->article->getKey();

    expect($result->outcome)->toBe(SaveOutcome::Created)
        ->and(saveArticleClassificationRow($id)->version_no)->toBe(1)
        ->and(saveArticleClassificationLinks($id))->toBe(['folder_id' => $folder->getKey(), 'client_id' => $owner['client'], 'project_id' => $owner['project']])
        ->and(saveArticleClassificationTags($id))->toBe(['Example tag']);

    $missing = saveArticleClassificationInvalid(fn () => $this->save->create($this->admin, ArticleInput::from('Example second article', 'Example text', false, classification: ArticleClassification::from((string) Str::uuid(), null, null, []))));

    expect(array_keys($missing?->errors() ?? []))->toBe(['folder_id']);
});

it('logs the link changes as allowlisted attributes and never a tag name', function (): void {
    $article = KnowledgeFixtures::article(['title' => 'Example article', 'body_md' => 'Example stored text']);
    $folder = KnowledgeFixtures::folder();
    $tag = 'Example '.Canary::canary('tag');

    $this->save->update($this->admin, $article, ArticleInput::from('Example article', 'Example stored text', false, classification: ArticleClassification::from($folder->getKey(), null, null, [$tag])), 1, 0);

    $json = saveArticleClassificationActivityJson($article->getKey());

    expect($json)->toContain($folder->getKey())
        ->and($json)->toContain('folder_id')
        ->and($json)->not->toContain($tag);

    expect(saveArticleClassificationSystem(static fn (): bool => KnowledgeFolder::query()->whereKey($folder->getKey())->exists()))->toBeTrue();
});
