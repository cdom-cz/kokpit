<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * Knowledge tags of an article (ORG-03; D-04, D-05 groundwork): the article carries tags of its own type, archiving
 * never strips them, and a sync of one type never touches another type. Helpers carry the prefix
 * `knowledgeArticleTags`, because Pest loads every test file into one namespace. Tag names are fictional.
 */

/**
 * Runs a callback as the system, so the Partner constraint of the tag model never hides a row.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function knowledgeArticleTagsSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The tag names of an article of one type, sorted.
 *
 * @return list<string>
 */
function knowledgeArticleTagsNames(string $id, string $type): array
{
    return knowledgeArticleTagsSystem(static function () use ($id, $type): array {
        $names = KnowledgeArticle::query()->withTrashed()->findOrFail($id)->tagsWithType($type)->pluck('name')->all();
        sort($names);

        return array_values($names);
    });
}

/**
 * The number of tag links of an article, whatever the type.
 */
function knowledgeArticleTagsLinks(string $id): int
{
    return DB::table('taggables')->where('taggable_id', $id)->count();
}

beforeEach(function (): void {
    $this->actingAs(Canary::admin());
    $this->article = KnowledgeFixtures::article();

    knowledgeArticleTagsSystem(function (): void {
        $this->article->attachTags(['Example server', 'Example backup'], 'knowledge');
        $this->article->attachTag('Example client tag', 'client');
    });
});

it('keeps knowledge tags and client tags of an article apart', function (): void {
    $knowledge = knowledgeArticleTagsSystem(fn (): int => $this->article->tagsWithType('knowledge')->count());

    expect($knowledge)->toBe(2)
        ->and(knowledgeArticleTagsNames($this->article->id, 'knowledge'))->toBe(['Example backup', 'Example server'])
        ->and(knowledgeArticleTagsNames($this->article->id, 'client'))->toBe(['Example client tag']);
});

it('keeps every tag link when the article is archived and restored', function (): void {
    expect(knowledgeArticleTagsLinks($this->article->id))->toBe(3);

    knowledgeArticleTagsSystem(fn () => $this->article->delete());

    expect(knowledgeArticleTagsLinks($this->article->id))->toBe(3)
        ->and(knowledgeArticleTagsNames($this->article->id, 'knowledge'))->toBe(['Example backup', 'Example server']);

    knowledgeArticleTagsSystem(fn () => $this->article->restore());

    expect(knowledgeArticleTagsLinks($this->article->id))->toBe(3)
        ->and(knowledgeArticleTagsNames($this->article->id, 'knowledge'))->toBe(['Example backup', 'Example server'])
        ->and(knowledgeArticleTagsNames($this->article->id, 'client'))->toBe(['Example client tag']);
});

it('returns an archived article unchanged from detachTags and keeps its links', function (): void {
    knowledgeArticleTagsSystem(function (): void {
        $this->article->delete();

        $returned = $this->article->detachTags(['Example server', 'Example backup'], 'knowledge');

        expect($returned)->toBe($this->article);
    });

    expect(knowledgeArticleTagsLinks($this->article->id))->toBe(3);

    knowledgeArticleTagsSystem(fn () => $this->article->restore());

    expect(knowledgeArticleTagsNames($this->article->id, 'knowledge'))->toBe(['Example backup', 'Example server']);
});

it('detaches the tags of an active article on request', function (): void {
    knowledgeArticleTagsSystem(fn () => $this->article->detachTags(['Example server'], 'knowledge'));

    expect(knowledgeArticleTagsNames($this->article->id, 'knowledge'))->toBe(['Example backup'])
        ->and(knowledgeArticleTagsNames($this->article->id, 'client'))->toBe(['Example client tag']);
});

it('syncs knowledge tags without touching the client tag of the same article', function (): void {
    knowledgeArticleTagsSystem(fn () => $this->article->syncTagsWithType(['Example backup'], 'knowledge'));

    expect(knowledgeArticleTagsNames($this->article->id, 'knowledge'))->toBe(['Example backup'])
        ->and(knowledgeArticleTagsNames($this->article->id, 'client'))->toBe(['Example client tag']);
});

it('finds an article carrying all of the named knowledge tags, not one carrying only some', function (): void {
    $partial = KnowledgeFixtures::article(['title' => 'Example partial article']);
    knowledgeArticleTagsSystem(fn () => $partial->attachTag('Example server', 'knowledge'));

    $both = knowledgeArticleTagsSystem(static fn (): array => KnowledgeArticle::query()
        ->withAllTags(['Example server', 'Example backup'], 'knowledge')
        ->pluck('id')
        ->all());
    $single = knowledgeArticleTagsSystem(static fn (): array => KnowledgeArticle::query()
        ->withAllTags(['Example server'], 'knowledge')
        ->orderBy('id')
        ->pluck('id')
        ->all());

    expect($both)->toBe([$this->article->id])
        ->and($single)->toEqualCanonicalizing([$this->article->id, $partial->id]);
});

it('writes no tag name to the activity log of the article', function (): void {
    knowledgeArticleTagsSystem(function (): void {
        $this->article->forceFill(['sensitive' => true])->save();
        $this->article->syncTagsWithType(['Example backup'], 'knowledge');
        $this->article->delete();
        $this->article->restore();
    });

    $rows = knowledgeArticleTagsSystem(static fn (): string => Activity::query()
        ->where('subject_id', test()->article->id)
        ->get()
        ->toJson());

    expect($rows)->not->toBe('[]')
        ->and($rows)->not->toContain('Example server')
        ->and($rows)->not->toContain('Example backup')
        ->and($rows)->not->toContain('Example client tag');
});
