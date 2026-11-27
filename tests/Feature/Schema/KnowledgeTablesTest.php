<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Models\KnowledgeArticleVersionImage;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Knowledge\Models\KnowledgeVersionRedaction;
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
 * The image and reference tables (Phase 20; D-02, D-04, D-06, D-16, D-17) are covered at the end.
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

/**
 * Inserts one redaction row of a version and returns its id; overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function knowledgeTablesRedaction(string $versionId, string $actorId, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('knowledge_version_redactions')->insert(array_merge([
        'id' => $id,
        'version_id' => $versionId,
        'actor_id' => $actorId,
        'scope' => 'lines',
        'reason_length' => 12,
        'created_at' => now(),
    ], $overrides));

    return $id;
}

/**
 * Sets the transaction-local redaction setting with set_config(..., is_local true), as the redaction Action will.
 * The Feature tests share one outer
 * transaction, so a test that switches the setting on and keeps the statement must switch it off again before it
 * leaves its closure (a refused statement rolls its savepoint back, and the setting with it).
 */
function knowledgeTablesRedactionSetting(string $value): void
{
    DB::select("select set_config('kokpit.redacting_knowledge_version', ?, true)", [$value]);
}

/**
 * The column names of a table, sorted.
 *
 * @return list<string>
 */
function knowledgeTablesColumns(string $table): array
{
    return DB::table('information_schema.columns')
        ->whereRaw('table_schema = current_schema()')
        ->where('table_name', $table)
        ->orderBy('column_name')
        ->pluck('column_name')
        ->all();
}

/**
 * Inserts one image row with valid defaults (a bound one, or a pending one with a NULL article) and returns its id;
 * overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function knowledgeTablesImage(?string $articleId, string $uploaderId, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('knowledge_article_images')->insert(array_merge([
        'id' => $id,
        'article_id' => $articleId,
        'uploaded_by' => $uploaderId,
        'file_name' => 'screenshot-example.png',
        'mime_type' => 'image/png',
        'width' => 2,
        'height' => 1,
        'size_bytes' => 70,
        'sha256' => str_repeat('a', 64),
        'created_at' => now(),
    ], $overrides));

    return $id;
}

/**
 * Inserts one reference row (a stored version names an image) and returns its id.
 */
function knowledgeTablesImageReference(string $versionId, string $imageId, string $articleId): string
{
    $id = (string) Str::uuid7();

    DB::table('knowledge_article_version_images')->insert([
        'id' => $id,
        'version_id' => $versionId,
        'image_id' => $imageId,
        'article_id' => $articleId,
        'created_at' => now(),
    ]);

    return $id;
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
        RawSql::expectSqlState('KP001', fn () => DB::statement('TRUNCATE knowledge_article_versions CASCADE'));

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

        RawSql::expectSqlState('23001', fn () => DB::table('knowledge_articles')->where('id', $article)->delete());
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

describe('folders', function (): void {
    it('refuses duplicate sibling names, also among roots and ignoring case, and a blank name', function (): void {
        $root = knowledgeTablesFolder('Example ops');

        RawSql::expectSqlState('23505', fn () => knowledgeTablesFolder('example OPS'));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesFolder('   ', $root));

        $first = knowledgeTablesFolder('Example docs');

        RawSql::expectAllowed(fn () => knowledgeTablesFolder('Runbooks', $root));
        RawSql::expectAllowed(fn () => knowledgeTablesFolder('Runbooks', $first));
        RawSql::expectSqlState('23505', fn () => knowledgeTablesFolder('RUNBOOKS', $root));
    });

    it('gives a folder a version 7 id from the column default', function (): void {
        DB::table('knowledge_folders')->insert(['name' => 'Example ops', 'created_at' => now(), 'updated_at' => now()]);

        expect(DB::table('knowledge_folders')->value('id'))->toMatch(Uuids::V7_PATTERN);
    });

    it('refuses a folder that is its own parent and a move into its own subtree', function (): void {
        $a = knowledgeTablesFolder('Folder A');
        $b = knowledgeTablesFolder('Folder B', $a);
        $c = knowledgeTablesFolder('Folder C', $b);

        RawSql::expectSqlState('KP003', fn () => DB::table('knowledge_folders')->where('id', $a)->update(['parent_id' => $a]));
        RawSql::expectSqlState('KP003', fn () => DB::table('knowledge_folders')->where('id', $a)->update(['parent_id' => $b]));
        RawSql::expectSqlState('KP003', fn () => DB::table('knowledge_folders')->where('id', $a)->update(['parent_id' => $c]));
        RawSql::expectAllowed(fn () => DB::table('knowledge_folders')->where('id', $c)->update(['parent_id' => $a]));
    });

    it('allows a chain of six nested folders and refuses a seventh level', function (): void {
        $parent = null;
        for ($level = 1; $level <= 6; $level++) {
            $parent = knowledgeTablesFolder('Level '.$level, $parent);
        }

        RawSql::expectSqlState('KP003', fn () => knowledgeTablesFolder('Level 7', $parent));
    });

    it('adds the height of a moved subtree to the depth of its new parent', function (): void {
        // A subtree of height 3: top -> middle -> leaf.
        $top = knowledgeTablesFolder('Subtree top');
        $middle = knowledgeTablesFolder('Subtree middle', $top);
        knowledgeTablesFolder('Subtree leaf', $middle);

        // A chain of four levels, and one of three.
        $four = null;
        for ($level = 1; $level <= 4; $level++) {
            $four = knowledgeTablesFolder('Deep '.$level, $four);
        }
        $three = null;
        for ($level = 1; $level <= 3; $level++) {
            $three = knowledgeTablesFolder('Shallow '.$level, $three);
        }

        // 4 + 3 > 6 is refused, 3 + 3 = 6 is allowed.
        RawSql::expectSqlState('KP003', fn () => DB::table('knowledge_folders')->where('id', $top)->update(['parent_id' => $four]));
        RawSql::expectAllowed(fn () => DB::table('knowledge_folders')->where('id', $top)->update(['parent_id' => $three]));
    });

    it('refuses to delete a folder with a child or an article', function (): void {
        $parent = knowledgeTablesFolder('Example ops');
        knowledgeTablesFolder('Runbooks', $parent);
        $filed = knowledgeTablesFolder('Filed');
        knowledgeTablesArticle(['folder_id' => $filed]);

        RawSql::expectSqlState('23001', fn () => DB::table('knowledge_folders')->where('id', $parent)->delete());
        RawSql::expectSqlState('23001', fn () => DB::table('knowledge_folders')->where('id', $filed)->delete());
    });

    it('files only a general article, and only in an existing folder', function (): void {
        $folder = knowledgeTablesFolder('Example ops');

        RawSql::expectSqlState('23503', fn () => knowledgeTablesArticle(['folder_id' => (string) Str::uuid7()]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['kind' => 'incident', 'folder_id' => $folder]));
        RawSql::expectAllowed(fn () => knowledgeTablesArticle(['folder_id' => $folder]));
    });

    it('reads the folder tree and its articles through the models', function (): void {
        $parent = knowledgeTablesFolder('Example ops');
        $child = knowledgeTablesFolder('Runbooks', $parent);
        $article = knowledgeTablesArticle(['folder_id' => $child]);

        [$children, $folder, $articles] = app(PartnerContext::class)->runAsSystem(static fn (): array => [
            KnowledgeFolder::query()->findOrFail($parent)->children()->get(),
            KnowledgeArticle::query()->findOrFail($article)->folder()->first(),
            KnowledgeFolder::query()->findOrFail($child)->articles()->get(),
        ]);

        expect($children->modelKeys())->toBe([$child])
            ->and($folder?->getKey())->toBe($child)
            ->and($articles->modelKeys())->toBe([$article])
            ->and((new KnowledgeFolder)->getMorphClass())->toBe('knowledge_folder')
            ->and(KnowledgeFolder::MAX_DEPTH)->toBe(6);
    });
});

describe('redaction and restore marker', function (): void {
    it('refuses a body rewrite of a version while the redaction setting is off', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId, ['body_md' => 'Example body']);

        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_versions')->where('id', $version)->update(['body_md' => '[redacted]']));

        expect(DB::table('knowledge_article_versions')->where('id', $version)->value('body_md'))->toBe('Example body');
    });

    it('admits a body-only rewrite while the setting is on and refuses it again once the setting is off', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId, ['body_md' => 'Example body']);

        RawSql::expectAllowed(function () use ($version): void {
            knowledgeTablesRedactionSetting('on');
            DB::table('knowledge_article_versions')->where('id', $version)->update(['body_md' => '[redacted]']);
            knowledgeTablesRedactionSetting('off');
        });

        expect(DB::table('knowledge_article_versions')->where('id', $version)->value('body_md'))->toBe('[redacted]');

        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_versions')->where('id', $version)->update(['body_md' => 'Rewritten again']));

        expect(DB::table('knowledge_article_versions')->where('id', $version)->value('body_md'))->toBe('[redacted]');
    });

    it('refuses a rewrite of every column but the body while the setting is on', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId, ['version_no' => 2]);
        $otherAuthor = User::factory()->create()->id;

        $changes = [
            ['title' => 'Changed title'],
            ['version_no' => 3],
            ['author_id' => $otherAuthor],
            ['created_at' => now()->subDay()],
            ['restored_from_version_no' => 1],
            ['body_md' => '[redacted]', 'title' => 'Changed title'],
        ];

        foreach ($changes as $change) {
            RawSql::expectSqlState('KP001', function () use ($version, $change): void {
                knowledgeTablesRedactionSetting('on');
                DB::table('knowledge_article_versions')->where('id', $version)->update($change);
            });
        }

        $row = DB::table('knowledge_article_versions')->where('id', $version)->first();

        expect($row->title)->toBe('Example runbook')
            ->and($row->version_no)->toBe(2)
            ->and($row->author_id)->toBe($this->authorId)
            ->and($row->body_md)->toBe('')
            ->and($row->restored_from_version_no)->toBeNull();
    });

    it('refuses to delete or truncate a version while the setting is on', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId);

        RawSql::expectSqlState('KP001', function () use ($version): void {
            knowledgeTablesRedactionSetting('on');
            DB::table('knowledge_article_versions')->where('id', $version)->delete();
        });
        RawSql::expectSqlState('KP001', function (): void {
            knowledgeTablesRedactionSetting('on');
            DB::statement('TRUNCATE knowledge_article_versions CASCADE');
        });

        expect(DB::table('knowledge_article_versions')->where('id', $version)->exists())->toBeTrue();
    });

    it('treats every setting value but exactly on as off', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId, ['body_md' => 'Example body']);

        foreach (['true', 'ON ', 'ON', '1', 'off', ''] as $value) {
            RawSql::expectSqlState('KP001', function () use ($version, $value): void {
                knowledgeTablesRedactionSetting($value);
                DB::table('knowledge_article_versions')->where('id', $version)->update(['body_md' => '[redacted]']);
            });
        }

        expect(DB::table('knowledge_article_versions')->where('id', $version)->value('body_md'))->toBe('Example body');
    });

    it('accepts a missing restore marker and a marker below the version number, and refuses the rest', function (): void {
        $article = knowledgeTablesArticle();
        knowledgeTablesVersion($article, $this->authorId);

        RawSql::expectAllowed(fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 2, 'restored_from_version_no' => 1]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 3, 'restored_from_version_no' => 3]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 3, 'restored_from_version_no' => 4]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 3, 'restored_from_version_no' => 0]));
        RawSql::expectAllowed(fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 3, 'restored_from_version_no' => null]));

        expect(DB::table('knowledge_article_versions')->where('article_id', $article)->orderBy('version_no')->pluck('restored_from_version_no')->all())
            ->toBe([null, 1, null]);
    });

    it('starts every article with redaction number 0 and refuses a negative one', function (): void {
        $article = knowledgeTablesArticle();

        expect(DB::table('knowledge_articles')->where('id', $article)->value('redaction_no'))->toBe(0);

        RawSql::expectSqlState('23514', fn () => knowledgeTablesArticle(['redaction_no' => -1]));
        RawSql::expectSqlState('23514', fn () => DB::table('knowledge_articles')->where('id', $article)->update(['redaction_no' => -1]));
        RawSql::expectAllowed(fn () => DB::table('knowledge_articles')->where('id', $article)->update(['redaction_no' => 1]));
    });

    it('records a redaction and refuses a bad scope, a bad reason length and unknown references', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId);

        $lines = knowledgeTablesRedaction($version, $this->authorId);
        $whole = knowledgeTablesRedaction($version, $this->authorId, ['scope' => 'whole', 'reason_length' => 500]);

        expect($lines)->toMatch(Uuids::V7_PATTERN)
            ->and(DB::table('knowledge_version_redactions')->where('id', $whole)->value('scope'))->toBe('whole')
            ->and(DB::table('knowledge_version_redactions')->where('id', $lines)->value('created_at'))->not->toBeNull();

        RawSql::expectSqlState('23514', fn () => knowledgeTablesRedaction($version, $this->authorId, ['scope' => 'partial']));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesRedaction($version, $this->authorId, ['reason_length' => 0]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesRedaction($version, $this->authorId, ['reason_length' => 501]));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesRedaction((string) Str::uuid7(), $this->authorId));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesRedaction($version, (string) Str::uuid7()));
    });

    it('lets the redaction column default allocate a version 7 id and the creation time', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId);

        DB::table('knowledge_version_redactions')->insert(['version_id' => $version, 'actor_id' => $this->authorId, 'scope' => 'lines', 'reason_length' => 1]);

        expect(DB::table('knowledge_version_redactions')->value('id'))->toMatch(Uuids::V7_PATTERN)
            ->and(DB::table('knowledge_version_redactions')->value('created_at'))->not->toBeNull();
    });

    it('refuses to update, delete or truncate a redaction row and keeps the row', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId);
        $redaction = knowledgeTablesRedaction($version, $this->authorId);

        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_version_redactions')->where('id', $redaction)->update(['reason_length' => 13]));
        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_version_redactions')->where('id', $redaction)->delete());
        RawSql::expectSqlState('KP001', fn () => DB::statement('TRUNCATE knowledge_version_redactions'));

        expect(DB::table('knowledge_version_redactions')->where('id', $redaction)->value('reason_length'))->toBe(12);
    });

    it('has no column that could hold the text of the reason', function (): void {
        expect(knowledgeTablesColumns('knowledge_version_redactions'))
            ->toBe(['actor_id', 'created_at', 'id', 'reason_length', 'scope', 'version_id']);
    });

    it('refuses to delete a version that has a redaction row', function (): void {
        $version = knowledgeTablesVersion(knowledgeTablesArticle(), $this->authorId);
        knowledgeTablesRedaction($version, $this->authorId);

        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_versions')->where('id', $version)->delete());

        expect(DB::table('knowledge_article_versions')->where('id', $version)->exists())->toBeTrue();
    });

    it('reads redactions through the models and carries no reason key', function (): void {
        $article = knowledgeTablesArticle();
        knowledgeTablesRedaction(knowledgeTablesVersion($article, $this->authorId), $this->authorId);
        knowledgeTablesVersion($article, $this->authorId, ['version_no' => 2]);

        [$flags, $model] = app(PartnerContext::class)->runAsSystem(static function () use ($article): array {
            $versions = KnowledgeArticleVersion::query()->where('article_id', $article)->withExists('redactions')->orderBy('version_no')->get();

            return [
                $versions->map(static fn (KnowledgeArticleVersion $version): bool => (bool) $version->redactions_exists)->all(),
                KnowledgeVersionRedaction::query()->firstOrFail(),
            ];
        });

        expect($flags)->toBe([true, false])
            ->and($model->scope)->toBe(RedactionScope::Lines)
            ->and($model->reason_length)->toBe(12)
            ->and($model->toArray())->not->toHaveKey('reason');
    });
});

describe('images and their references', function (): void {
    it('stores a pending and a bound image with a version 7 id and a creation time', function (): void {
        $article = knowledgeTablesArticle();

        $pending = knowledgeTablesImage(null, $this->authorId);
        $bound = knowledgeTablesImage($article, $this->authorId);

        expect($pending)->toMatch(Uuids::V7_PATTERN)
            ->and(DB::table('knowledge_article_images')->where('id', $pending)->value('article_id'))->toBeNull()
            ->and(DB::table('knowledge_article_images')->where('id', $bound)->value('article_id'))->toBe($article)
            ->and(DB::table('knowledge_article_images')->where('id', $bound)->value('created_at'))->not->toBeNull();

        DB::table('knowledge_article_images')->insert([
            'uploaded_by' => $this->authorId,
            'file_name' => 'screenshot-example.png',
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'size_bytes' => 1,
            'sha256' => str_repeat('b', 64),
        ]);

        expect(DB::table('knowledge_article_images')->where('sha256', str_repeat('b', 64))->value('id'))->toMatch(Uuids::V7_PATTERN);
    });

    it('refuses a mime type outside PNG, JPEG, WebP and GIF', function (): void {
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['mime_type' => 'image/svg+xml']));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['mime_type' => 'image/bmp']));

        foreach (['image/png', 'image/jpeg', 'image/webp', 'image/gif'] as $mime) {
            RawSql::expectAllowed(fn () => knowledgeTablesImage(null, $this->authorId, ['mime_type' => $mime]));
        }
    });

    it('refuses a width or height outside 1 to 2400', function (): void {
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['width' => 0]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['width' => 2401]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['height' => 0]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['height' => 2401]));
        RawSql::expectAllowed(fn () => knowledgeTablesImage(null, $this->authorId, ['width' => 2400, 'height' => 2400]));
        RawSql::expectAllowed(fn () => knowledgeTablesImage(null, $this->authorId, ['width' => 1, 'height' => 1]));
    });

    it('refuses a size outside 1 byte to 32 MiB', function (): void {
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['size_bytes' => 0]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['size_bytes' => 32 * 1024 * 1024 + 1]));
        RawSql::expectAllowed(fn () => knowledgeTablesImage(null, $this->authorId, ['size_bytes' => 32 * 1024 * 1024]));
        RawSql::expectAllowed(fn () => knowledgeTablesImage(null, $this->authorId, ['size_bytes' => 1]));
    });

    it('refuses a malformed sha256 and a file name of 0 or over 255 characters', function (): void {
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['sha256' => str_repeat('a', 63)]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['sha256' => str_repeat('a', 65)]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['sha256' => str_repeat('A', 64)]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['sha256' => str_repeat('g', 64)]));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['file_name' => '']));
        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['file_name' => str_repeat('a', 256)]));
        RawSql::expectAllowed(fn () => knowledgeTablesImage(null, $this->authorId, ['file_name' => str_repeat('a', 255)]));
    });

    it('refuses an orphaned_at on a pending image and admits it on a bound one', function (): void {
        $article = knowledgeTablesArticle();

        RawSql::expectSqlState('23514', fn () => knowledgeTablesImage(null, $this->authorId, ['orphaned_at' => now()]));
        RawSql::expectAllowed(fn () => knowledgeTablesImage($article, $this->authorId, ['orphaned_at' => now()]));
    });

    it('refuses an unknown article and an unknown uploader', function (): void {
        RawSql::expectSqlState('23503', fn () => knowledgeTablesImage((string) Str::uuid7(), $this->authorId));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesImage(null, (string) Str::uuid7()));
    });

    it('lets a pending image be bound once and refuses every other change of article_id', function (): void {
        $articleA = knowledgeTablesArticle();
        $articleB = knowledgeTablesArticle();
        $image = knowledgeTablesImage(null, $this->authorId);

        RawSql::expectAllowed(fn () => DB::table('knowledge_article_images')->where('id', $image)->update(['article_id' => $articleA]));

        expect(DB::table('knowledge_article_images')->where('id', $image)->value('article_id'))->toBe($articleA);

        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_images')->where('id', $image)->update(['article_id' => $articleB]));
        RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_images')->where('id', $image)->update(['article_id' => null]));

        expect(DB::table('knowledge_article_images')->where('id', $image)->value('article_id'))->toBe($articleA);
    });

    it('refuses to change any stored fact of an image', function (): void {
        $article = knowledgeTablesArticle();
        $image = knowledgeTablesImage($article, $this->authorId);
        $otherUser = User::factory()->create()->id;

        $changes = [
            ['mime_type' => 'image/jpeg'],
            ['width' => 3],
            ['height' => 2],
            ['size_bytes' => 71],
            ['sha256' => str_repeat('c', 64)],
            ['file_name' => 'renamed-example.png'],
            ['uploaded_by' => $otherUser],
            ['created_at' => now()->subDay()],
            ['orphaned_at' => now(), 'width' => 3],
        ];

        foreach ($changes as $change) {
            RawSql::expectSqlState('KP001', fn () => DB::table('knowledge_article_images')->where('id', $image)->update($change));
        }

        $row = DB::table('knowledge_article_images')->where('id', $image)->first();

        expect($row->mime_type)->toBe('image/png')
            ->and($row->width)->toBe(2)
            ->and($row->height)->toBe(1)
            ->and($row->size_bytes)->toBe(70)
            ->and($row->sha256)->toBe(str_repeat('a', 64))
            ->and($row->file_name)->toBe('screenshot-example.png')
            ->and($row->uploaded_by)->toBe($this->authorId)
            ->and($row->orphaned_at)->toBeNull();
    });

    it('lets the orphan stamp be set and cleared on a bound image', function (): void {
        $image = knowledgeTablesImage(knowledgeTablesArticle(), $this->authorId);

        RawSql::expectAllowed(fn () => DB::table('knowledge_article_images')->where('id', $image)->update(['orphaned_at' => now()]));

        expect(DB::table('knowledge_article_images')->where('id', $image)->value('orphaned_at'))->not->toBeNull();

        RawSql::expectAllowed(fn () => DB::table('knowledge_article_images')->where('id', $image)->update(['orphaned_at' => null]));

        expect(DB::table('knowledge_article_images')->where('id', $image)->value('orphaned_at'))->toBeNull();
    });

    it('refuses to stamp a pending image as orphaned', function (): void {
        $image = knowledgeTablesImage(null, $this->authorId);

        RawSql::expectSqlState('23514', fn () => DB::table('knowledge_article_images')->where('id', $image)->update(['orphaned_at' => now()]));
    });

    it('deletes an unreferenced image', function (): void {
        $image = knowledgeTablesImage(knowledgeTablesArticle(), $this->authorId);

        RawSql::expectAllowed(fn () => DB::table('knowledge_article_images')->where('id', $image)->delete());

        expect(DB::table('knowledge_article_images')->where('id', $image)->exists())->toBeFalse();
    });

    it('inserts a reference of a version and an image of the same article and refuses the same pair twice', function (): void {
        $article = knowledgeTablesArticle();
        $version = knowledgeTablesVersion($article, $this->authorId);
        $image = knowledgeTablesImage($article, $this->authorId);

        $reference = knowledgeTablesImageReference($version, $image, $article);

        expect($reference)->toMatch(Uuids::V7_PATTERN);

        RawSql::expectSqlState('23505', fn () => knowledgeTablesImageReference($version, $image, $article));

        $other = knowledgeTablesImage($article, $this->authorId);
        RawSql::expectAllowed(fn () => knowledgeTablesImageReference($version, $other, $article));
    });

    it('refuses a reference across two articles', function (): void {
        $articleA = knowledgeTablesArticle();
        $articleB = knowledgeTablesArticle();
        $versionA = knowledgeTablesVersion($articleA, $this->authorId);
        $versionB = knowledgeTablesVersion($articleB, $this->authorId);
        $imageA = knowledgeTablesImage($articleA, $this->authorId);

        // A version and an image of article A, but the row claims article B.
        RawSql::expectSqlState('23503', fn () => knowledgeTablesImageReference($versionA, $imageA, $articleB));
        // A version of article B naming an image of article A, claiming A or B.
        RawSql::expectSqlState('23503', fn () => knowledgeTablesImageReference($versionB, $imageA, $articleA));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesImageReference($versionB, $imageA, $articleB));

        expect(DB::table('knowledge_article_version_images')->count())->toBe(0);
    });

    it('refuses a reference to a pending image and to an unknown version or image', function (): void {
        $article = knowledgeTablesArticle();
        $version = knowledgeTablesVersion($article, $this->authorId);
        $pending = knowledgeTablesImage(null, $this->authorId);
        $bound = knowledgeTablesImage($article, $this->authorId);

        RawSql::expectSqlState('23503', fn () => knowledgeTablesImageReference($version, $pending, $article));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesImageReference((string) Str::uuid7(), $bound, $article));
        RawSql::expectSqlState('23503', fn () => knowledgeTablesImageReference($version, (string) Str::uuid7(), $article));
    });

    it('refuses to delete an image that a reference names and allows it once the reference is gone', function (): void {
        $article = knowledgeTablesArticle();
        $version = knowledgeTablesVersion($article, $this->authorId);
        $image = knowledgeTablesImage($article, $this->authorId);
        $reference = knowledgeTablesImageReference($version, $image, $article);

        RawSql::expectSqlState('23503', fn () => DB::table('knowledge_article_images')->where('id', $image)->delete());

        expect(DB::table('knowledge_article_images')->where('id', $image)->exists())->toBeTrue();

        RawSql::expectAllowed(fn () => DB::table('knowledge_article_version_images')->where('id', $reference)->delete());
        RawSql::expectAllowed(fn () => DB::table('knowledge_article_images')->where('id', $image)->delete());

        expect(DB::table('knowledge_article_images')->where('id', $image)->exists())->toBeFalse();
    });

    it('refuses to hard delete an article that has an image', function (): void {
        $article = knowledgeTablesArticle();
        knowledgeTablesImage($article, $this->authorId);

        RawSql::expectSqlState('23001', fn () => DB::table('knowledge_articles')->where('id', $article)->delete());
    });

    it('keeps the version rows usable after the composite unique key was added', function (): void {
        $article = knowledgeTablesArticle();
        $version = knowledgeTablesVersion($article, $this->authorId);

        expect(DB::table('knowledge_article_versions')->where('id', $version)->value('article_id'))->toBe($article);

        RawSql::expectAllowed(fn () => knowledgeTablesVersion($article, $this->authorId, ['version_no' => 2]));
    });

    it('builds the object key from the id and the mime type only', function (): void {
        $png = (new KnowledgeArticleImage)->forceFill(['id' => 'abc', 'mime_type' => 'image/png', 'file_name' => 'x/../evil.png']);
        $jpeg = (new KnowledgeArticleImage)->forceFill(['id' => 'abc', 'mime_type' => 'image/jpeg']);
        $webp = (new KnowledgeArticleImage)->forceFill(['id' => 'abc', 'mime_type' => 'image/webp']);
        $gif = (new KnowledgeArticleImage)->forceFill(['id' => 'abc', 'mime_type' => 'image/gif']);
        $svg = (new KnowledgeArticleImage)->forceFill(['id' => 'abc', 'mime_type' => 'image/svg+xml']);

        expect($png->storagePath())->toBe('knowledge/images/abc.png')
            ->and($jpeg->storagePath())->toBe('knowledge/images/abc.jpg')
            ->and($webp->storagePath())->toBe('knowledge/images/abc.webp')
            ->and($gif->storagePath())->toBe('knowledge/images/abc.gif')
            ->and(KnowledgeArticleImage::STORAGE_DIRECTORY)->toBe('knowledge/images')
            ->and(fn () => $svg->storagePath())->toThrow(LogicException::class);
    });

    it('reads images and references through the models and their relations', function (): void {
        $article = knowledgeTablesArticle();
        $version = knowledgeTablesVersion($article, $this->authorId);
        $image = knowledgeTablesImage($article, $this->authorId);
        knowledgeTablesImageReference($version, $image, $article);

        [$images, $references, $imageOfReference, $referencesOfImage] = app(PartnerContext::class)->runAsSystem(static function () use ($article, $version, $image): array {
            $reference = KnowledgeArticleVersionImage::query()->firstOrFail();

            return [
                KnowledgeArticle::query()->findOrFail($article)->images()->get(),
                KnowledgeArticleVersion::query()->findOrFail($version)->imageReferences()->get(),
                $reference->image()->first(),
                KnowledgeArticleImage::query()->findOrFail($image)->references()->get(),
            ];
        });

        expect($images->modelKeys())->toBe([$image])
            ->and($references->first())->toBeInstanceOf(KnowledgeArticleVersionImage::class)
            ->and($references->first()?->image_id)->toBe($image)
            ->and($imageOfReference?->getKey())->toBe($image)
            ->and($referencesOfImage)->toHaveCount(1)
            ->and($images->first()?->width)->toBe(2)
            ->and((new KnowledgeArticleImage)->getMorphClass())->toBe('knowledge_article_image')
            ->and((new KnowledgeArticleVersionImage)->getMorphClass())->toBe('knowledge_article_version_image');
    });
});
