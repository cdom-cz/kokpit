<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The version history of a knowledge article (Phase 18; VER-01; D-11; ruling R-18-01-5 of plan 18-01).
 *
 * A version is a full snapshot of the title and the Markdown body at one save (D-11), not a diff. The number rises by one
 * per content-changing save; `SaveArticle` allocates it under a lock on the article row and writes the article row and
 * this row in one transaction. The first version (number 1) is written together with the article.
 *
 * Rules enforced here, for every writer (Actions, console, raw SQL):
 * - A version row is immutable. The row trigger `knowledge_article_versions_frozen` raises KP001 on every UPDATE and
 *   DELETE, and the truncate guard refuses TRUNCATE. The Phase 19 redaction replaces the function with a variant that
 *   admits a transaction-local setting; nothing of that is built now.
 * - `(article_id, version_no)` is unique, so a duplicate number is refused by the database even if two writers race.
 * - The same title and body limits as the article row (200 and 100 000 characters), so a stored snapshot can always be
 *   rendered.
 * - Both foreign keys are RESTRICT: an article that has a version cannot be hard deleted, and neither can its author.
 * - There is no `updated_at`: the row never changes.
 *
 * No DEFERRABLE foreign key from the article to its current version row, on purpose: it would fire only at commit, so
 * the RefreshDatabase Feature tests could never observe it. The pair of rows is kept by the one write path `SaveArticle`
 * (one transaction under the row lock), proven by its tests.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and `session_replication_role = replica`
 * skips them, so the production application role must not be a superuser.
 *
 * down() drops the triggers, the table and the function. Rolling back deletes the history; once versions exist that is a
 * deliberate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_article_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->uuid('article_id');
            $table->integer('version_no');
            $table->text('title');
            $table->text('body_md');
            $table->uuid('author_id');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('author_id', 'knowledge_article_versions_author_id_index');
        });

        DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_article_fk FOREIGN KEY (article_id) REFERENCES knowledge_articles (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_author_fk FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_article_version_unique UNIQUE (article_id, version_no)');
        DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_version_no_check CHECK (version_no >= 1)');
        DB::statement("ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_title_check CHECK (btrim(title) <> '' AND char_length(title) <= 200)");
        DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_body_length_check CHECK (char_length(body_md) <= 100000)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_version_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'a knowledge article version is never changed or deleted' USING ERRCODE = 'KP001';
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER knowledge_article_versions_frozen BEFORE UPDATE OR DELETE ON knowledge_article_versions FOR EACH ROW EXECUTE FUNCTION kokpit_refuse_knowledge_version_change()');
        DB::unprepared(Immutability::truncateGuardSql('knowledge_article_versions'));
    }

    public function down(): void
    {
        DB::unprepared(Immutability::dropTruncateGuardSql('knowledge_article_versions'));
        DB::unprepared('DROP TRIGGER IF EXISTS knowledge_article_versions_frozen ON knowledge_article_versions');

        Schema::dropIfExists('knowledge_article_versions');

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_refuse_knowledge_version_change()');
    }
};
