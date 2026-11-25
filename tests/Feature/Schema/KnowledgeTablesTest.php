<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RawSql;
use Tests\Support\Uuids;

/*
 * Database-level invariants of the knowledge tables (KB-01, KB-07, VER-01; D-11, A7, A8).
 * Every case uses raw SQL, so no model, cast, Action or form rule stands between the value and
 * the constraint. Each refused statement runs in its own savepoint (RawSql), because a failed
 * statement aborts a PostgreSQL transaction. Values are fictional. Helpers carry the prefix
 * `knowledgeTables`, because Pest loads every test file into one namespace.
 */

/**
 * Inserts one article row with valid defaults and returns its id; overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function knowledgeTablesArticle(array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('knowledge_articles')->insert(array_merge([
        'id' => $id,
        'kind' => 'general',
        'title' => 'Example runbook',
        'body_md' => '',
        'version_no' => 1,
        'edited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

/**
 * Inserts one version row of an article and returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function knowledgeTablesVersion(string $articleId, string $authorId, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('knowledge_article_versions')->insert(array_merge([
        'id' => $id,
        'article_id' => $articleId,
        'version_no' => 1,
        'title' => 'Example runbook',
        'body_md' => '',
        'author_id' => $authorId,
        'created_at' => now(),
    ], $overrides));

    return $id;
}

/**
 * Inserts one folder row and returns its id.
 */
function knowledgeTablesFolder(string $name, ?string $parentId = null): string
{
    $id = (string) Str::uuid7();

    DB::table('knowledge_folders')->insert([
        'id' => $id,
        'parent_id' => $parentId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * A client and one of its projects (factories), as ids.
 *
 * @return array{client: string, project: string}
 */
function knowledgeTablesState(): array
{
    return app(PartnerContext::class)->runAsSystem(static function (): array {
        $project = Project::factory()->create();

        return ['client' => $project->client_id, 'project' => $project->id];
    });
}

beforeEach(function (): void {
    $this->authorId = User::factory()->create()->id;
});

describe('articles and versions', function (): void {
    it('stores an article with its first version', function (): void {
        $article = knowledgeTablesArticle();
        $version = knowledgeTablesVersion($article, $this->authorId);

        expect(DB::table('knowledge_article_versions')->where('id', $version)->value('article_id'))->toBe($article);
    });

    it('lets the column default allocate a version 7 id when the writer gives none', function (): void {
        DB::table('knowledge_articles')->insert([
            'kind' => 'general',
            'title' => 'Example runbook',
            'edited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $article = (string) DB::table('knowledge_articles')->value('id');

        DB::table('knowledge_article_versions')->insert([
            'article_id' => $article,
            'version_no' => 1,
            'title' => 'Example runbook',
            'body_md' => '',
            'author_id' => $this->authorId,
        ]);

        expect($article)->toMatch(Uuids::V7_PATTERN)
            ->and(DB::table('knowledge_article_versions')->value('id'))->toMatch(Uuids::V7_PATTERN);
    });

    it('refuses a second version row with the same version number of an article', function (): void {
        $article = knowledgeTablesArticle();
        knowledgeTablesVersion($article, $this->authorId);

        RawSql::expectSqlState('23505', fn () => knowledgeTablesVersion($article, $this->authorId));
        RawSql::expectAllowed(fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 2]));
    });

    it('refuses to update, delete or truncate a version row and keeps the row', function (): void {
        $article = knowledgeTablesArticle();
        $version = knowledgeTablesVersion($article, $this->authorId);

        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_versions')->where('id', $version)->update(['title' => 'Changed']));
        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_versions')->where('id', $version)->delete());
        RawSql::expectSqlState('KP001', fn () => DB::statement('TRUNCATE knowledge_article_versions'));

        expect(DB::table('knowledge_article_versions')->where('id', $version)->value('title'))->toBe('Example runbook');
    });

    it('freezes the kind of an article but lets the rest change', function (): void {
        $article = knowledgeTablesArticle();

        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_articles')->where('id', $article)->update(['kind' => 'incident']));
        RawSql::expectAllowed(fn () => DB::table('knowledge_articles')->where('id', $article)->update(['title' => 'Renamed runbook']));

        expect(DB::table('knowledge_articles')->where('id', $article)->value('kind'))->toBe('general');
    });

    it('refuses to hard delete an article that has a version and allows a soft delete', function (): void {
        $article = knowledgeTablesArticle();
        knowledgeTablesVersion($article, $this->authorId);

        RawSql::expectSqlState('23503', fn () => DB::table('knowledge_articles')->where('id', $article)->delete());
        RawSql::expectAllowed(fn () => DB::table('knowledge_articles')->where('id', $article)->update(['deleted_at' => now()]));

        expect(DB::table('knowledge_articles')->where('id', $article)->exists())->toBeTrue();
    });

    it('refuses a blank title, a title over 200 characters and a body over 100 000 characters', function (): void {
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['title' => '   ']));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['title' => str_repeat('a', 201)]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['body_md' => str_repeat('a', 100001)]));
        RawSql::expectAllowed(fn () => knowledgeTablesArticle(['title' => str_repeat('a', 200)]));
        RawSql::expectAllowed(fn () => knowledgeTablesArticle(['body_md' => str_repeat('a', 100000)]));
    });

    it('refuses an unknown kind and a version number below 1', function (): void {
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['kind' => 'wiki']));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['version_no' => 0]));
    });

    it('ties an incident sub-page to a parent and nothing else to one', function (): void {
        $incident = knowledgeTablesArticle(['kind' => 'incident']);

        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['kind' => 'incident_page']));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['kind' => 'general', 'parent_id' => $incident]));
        RawSql::expectAllowed(fn () => knowledgeTablesArticle(['kind' => 'incident_page', 'parent_id' => $incident]));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesArticle(['kind' => 'incident_page', 'parent_id' => (string) Str::uuid7()]));
    });

    it('refuses a project without a client and a project of another client', function (): void {
        $own = knowledgeTablesState();
        $other = knowledgeTablesState();

        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['project_id' => $own['project']]));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesArticle(['client_id' => $own['client'], 'project_id' => $other['project']]));
        RawSql::expectAllowed(fn () => knowledgeTablesArticle(['client_id' => $own['client'], 'project_id' => $own['project']]));
    });

    it('refuses client_visible without a client, with sensitive, and on a retrospective or a sub-page', function (): void {
        $state = knowledgeTablesState();
        $incident = knowledgeTablesArticle(['kind' => 'incident']);

        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['client_visible' => true]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['client_id' => $state['client'], 'client_visible' => true, 'sensitive' => true]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['kind' => 'retrospective', 'client_id' => $state['client'], 'client_visible' => true]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['kind' => 'incident_page', 'parent_id' => $incident, 'client_id' => $state['client'], 'client_visible' => true]));
        RawSql::expectAllowed(fn () => knowledgeTablesArticle(['client_id' => $state['client'], 'client_visible' => true]));
        RawSql::expectAllowed(fn () => knowledgeTablesArticle(['kind' => 'incident', 'client_id' => $state['client'], 'client_visible' => true]));
    });

    it('refuses a version row with a blank title, a long title or a body over 100 000 characters', function (): void {
        $article = knowledgeTablesArticle();

        RawSql::expectSqlState('23514', fn () => knowledgeTablesVersion($article, $this->authorId, ['title' => ' ']));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesVersion($article, $this->authorId, ['title' => str_repeat('a', 201)]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesVersion($article, $this->authorId, ['body_md' => str_repeat('a', 100001)]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 0]));
        RawSql::expectAllowed(fn () => knowledgeTablesVersion($article, $this->authorId, ['body_md' => str_repeat('a', 100000)]));
    });

    it('reads an article and its versions through the models', function (): void {
        $article = knowledgeTablesArticle(['title' => 'Example runbook']);
        knowledgeTablesVersion($article, $this->authorId);

        [$kind, $versions] = app(PartnerContext::class)->runAsSystem(static function () use ($article): array {
            $model = KnowledgeArticle::query()->findOrFail($article);

            return [$model->kind, $model->versions()->get()];
        });

        expect($kind)->toBe(ArticleKind::General)
            ->and($versions)->toHaveCount(1)
            ->and($versions->first())->toBeInstanceOf(KnowledgeArticleVersion::class)
            ->and((new KnowledgeArticle)->getMorphClass())->toBe('knowledge_article')
            ->and((new KnowledgeArticleVersion)->getMorphClass())->toBe('knowledge_article_version')
            ->and(KnowledgeArticle::MAX_TITLE_LENGTH)->toBe(200)
            ->and(KnowledgeArticle::MAX_BODY_LENGTH)->toBe(100000);
    });

    it('has a Czech label for the incident sub-page kind', function (): void {
        expect(ArticleKind::from('incident_page')->getLabel())->toBe('Podstránka incidentu');
    });
});
