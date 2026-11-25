<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Policies\AdminOnlyPolicy;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The knowledge articles, their versions and the folder tree are the Admin's internal notes (Phase 18): a Partner,
 * a Partner without a client, a user without a role and a guest read zero rows of the three models, and a Partner
 * is granted no ability on them (fail-closed seam, roadmap success criterion 5). Every text is fictional.
 */

/**
 * Seeds, inside system runs, an article for client A, one for client B, one without a client and an archived one,
 * each with its version rows, and two folders. Returns the article, version and folder records the ability
 * checks use.
 *
 * @return array{article: KnowledgeArticle, version: KnowledgeArticleVersion, folder: KnowledgeFolder}
 */
function knowledgeVisibilitySeed(): array
{
    [$clientA, $clientB] = Canary::twoClients();
    $author = Canary::admin();

    $article = KnowledgeFixtures::article(['title' => Canary::canary('article_a'), 'client_id' => $clientA], $author);
    KnowledgeFixtures::article(['title' => Canary::canary('article_b'), 'client_id' => $clientB, 'version_no' => 2], $author);
    KnowledgeFixtures::article(['title' => Canary::canary('article_none')], $author);
    KnowledgeFixtures::archived(['title' => Canary::canary('article_archived')], $author);

    $folder = KnowledgeFixtures::folder(Canary::canary('folder_root'));
    KnowledgeFixtures::folder(Canary::canary('folder_child'), $folder);

    $version = app(PartnerContext::class)->runAsSystem(static fn (): KnowledgeArticleVersion => KnowledgeArticleVersion::query()->where('article_id', $article->getKey())->firstOrFail());

    return ['article' => $article, 'version' => $version, 'folder' => $folder];
}

/**
 * Row counts of the three models as seen by the current user, archived articles included.
 *
 * @return array<string, int>
 */
function knowledgeVisibilityCounts(): array
{
    return [
        'articles' => KnowledgeArticle::query()->count(),
        'articles with archived' => KnowledgeArticle::withTrashed()->count(),
        'versions' => KnowledgeArticleVersion::query()->count(),
        'folders' => KnowledgeFolder::query()->count(),
    ];
}

it('shows an Admin every row of the three knowledge models, archived articles through withTrashed', function (): void {
    knowledgeVisibilitySeed();
    $this->actingAs(Canary::admin());

    // Four articles (one archived), 1 + 2 + 1 + 1 version rows, two folders.
    expect(knowledgeVisibilityCounts())->toBe(['articles' => 3, 'articles with archived' => 4, 'versions' => 5, 'folders' => 2]);
});

it('shows a Partner with a client none of the knowledge rows, archived articles included', function (): void {
    knowledgeVisibilitySeed();
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    expect(knowledgeVisibilityCounts())->toBe(['articles' => 0, 'articles with archived' => 0, 'versions' => 0, 'folders' => 0]);
});

it('shows a Partner of the client of an article none of its rows', function (): void {
    [$clientA] = Canary::twoClients();
    KnowledgeFixtures::article(['title' => Canary::canary('article_own'), 'client_id' => $clientA]);
    $this->actingAs(Canary::partnerFor($clientA));

    expect(knowledgeVisibilityCounts())->toBe(['articles' => 0, 'articles with archived' => 0, 'versions' => 0, 'folders' => 0]);
});

it('shows a Partner without a client, a user without a role and a guest none of the knowledge rows', function (): void {
    knowledgeVisibilitySeed();
    $none = ['articles' => 0, 'articles with archived' => 0, 'versions' => 0, 'folders' => 0];

    // A guest: no signed-in user, outside a system run.
    expect(knowledgeVisibilityCounts())->toBe($none);

    $this->actingAs(Canary::partnerFor(null));
    expect(knowledgeVisibilityCounts())->toBe($none);

    $this->actingAs(Canary::userWithoutRole(Canary::twoClients()[0]));
    expect(knowledgeVisibilityCounts())->toBe($none);
});

it('keeps the versions of an article hidden from a Partner through the relation', function (): void {
    $rows = knowledgeVisibilitySeed();
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    expect($rows['article']->versions()->count())->toBe(0)
        ->and(KnowledgeArticle::query()->find($rows['article']->getKey()))->toBeNull()
        ->and(KnowledgeArticle::withTrashed()->find($rows['article']->getKey()))->toBeNull();
});

it('denies a Partner every ability on the three knowledge models and admits the Admin', function (): void {
    $rows = knowledgeVisibilitySeed();
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $admin = Canary::admin();

    /** @var array<class-string<Model>, Model> $records */
    $records = [
        KnowledgeArticle::class => $rows['article'],
        KnowledgeArticleVersion::class => $rows['version'],
        KnowledgeFolder::class => $rows['folder'],
    ];

    foreach ($records as $class => $record) {
        foreach ([[$partner, false], [$admin, true]] as [$user, $expected]) {
            expect(Gate::forUser($user)->allows('viewAny', $class))->toBe($expected, "{$class} viewAny")
                ->and(Gate::forUser($user)->allows('create', $class))->toBe($expected, "{$class} create");

            foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
                expect(Gate::forUser($user)->allows($ability, $record))->toBe($expected, "{$class} {$ability}");
            }
        }
    }
});

it('registers the admin-only policy for the three knowledge models', function (): void {
    foreach ([KnowledgeArticle::class, KnowledgeArticleVersion::class, KnowledgeFolder::class] as $class) {
        expect(Gate::getPolicyFor($class))->toBeInstanceOf(AdminOnlyPolicy::class);
    }
});

it('shows a Partner no tag of the knowledge type, while the Admin reads it', function (): void {
    app(PartnerContext::class)->runAsSystem(static fn () => Tag::findOrCreate('example-topic', TagType::Knowledge->value));

    $this->actingAs(Canary::admin());
    expect(Tag::query()->where('type', TagType::Knowledge->value)->count())->toBe(1);

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));
    expect(Tag::query()->count())->toBe(0)
        ->and(Tag::query()->where('type', TagType::Knowledge->value)->count())->toBe(0);
});
