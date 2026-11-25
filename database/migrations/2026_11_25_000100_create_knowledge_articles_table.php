<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The knowledge article (Phase 18; KB-01, KB-07, VER-01; D-11; rulings R-18-01-1 to R-18-01-4 of plan 18-01; research
 * assumptions A7 and A8).
 *
 * One table holds every kind of article: a general article, an incident (the hub), an incident sub-page and a
 * retrospective. Later phases add their own columns (the page type, the incident metadata, the search vector); nothing of
 * that is here. The title and the Markdown body of the CURRENT version live on this row; every version, including the
 * current one, is a full snapshot in `knowledge_article_versions` (D-11).
 *
 * The title and the body are never written to the activity log: the model logs metadata only (KnowledgeArticle).
 *
 * Rules enforced here, for every writer (Actions, console, raw SQL):
 * - The kind is one of four fixed names and never changes after insert (A7). The row trigger
 *   `knowledge_articles_kind_frozen` raises KP001 on any UPDATE of `kind`.
 * - An incident sub-page has a parent and nothing else has one: `(kind = 'incident_page') = (parent_id IS NOT NULL)`. The
 *   parent is a RESTRICT foreign key to the same table; that the parent is an incident is a rule of the Actions (a
 *   Phase 21 concern), not of this table.
 * - The title is not blank and at most 200 characters, the body at most 100 000 characters (A8), so no writer can store a
 *   body the renderer cannot handle. The literals equal KnowledgeArticle::MAX_TITLE_LENGTH and MAX_BODY_LENGTH.
 * - The version number is at least 1. That it rises by exactly one per content-changing save is a rule of `SaveArticle`
 *   under a row lock, proved by its concurrency test; `knowledge_article_versions` refuses a duplicate number.
 * - A folder holds only general articles (`folder_id IS NULL OR kind = 'general'`). The folder foreign key itself is added
 *   by the folders migration, because the folders table does not exist yet.
 * - A project belongs to the client of the article through the composite foreign key `(project_id, client_id)`; a project
 *   needs a client.
 * - `client_visible` (the share switch of Phase 23, no UI before it) needs a client, is never combined with `sensitive`,
 *   and is never set on an incident sub-page or a retrospective.
 * - Every foreign key is RESTRICT: an article with a version row cannot be hard deleted (the append-only versions keep
 *   the foreign key alive). Archiving is the soft delete through `deleted_at` (KB-07).
 * - `edited_at` has no default: only `SaveArticle` and the test fixtures write it. Archive and restore never touch it, so
 *   they do not reorder the list.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and `session_replication_role = replica`
 * skips them, so the production application role must not be a superuser.
 *
 * down() drops the trigger, the table and the function.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->text('kind');
            // Self foreign key (incident sub-page to its incident) added below.
            $table->uuid('parent_id')->nullable();

            $table->text('title');
            $table->text('body_md')->default('');
            $table->integer('version_no')->default(1);
            $table->timestampTz('edited_at');

            $table->boolean('sensitive')->default(false);

            // The folder foreign key is added by the folders migration; client and project by the statements below.
            $table->uuid('folder_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->boolean('client_visible')->default(false);

            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['kind', 'edited_at'], 'knowledge_articles_kind_edited_at_index');
            $table->index('parent_id', 'knowledge_articles_parent_id_index');
            $table->index('folder_id', 'knowledge_articles_folder_id_index');
            $table->index('client_id', 'knowledge_articles_client_id_index');
            $table->index('project_id', 'knowledge_articles_project_id_index');
        });

        DB::statement("ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_kind_check CHECK (kind IN ('general', 'incident', 'incident_page', 'retrospective'))");
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_parent_fk FOREIGN KEY (parent_id) REFERENCES knowledge_articles (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_parent_check CHECK ((kind = 'incident_page') = (parent_id IS NOT NULL))");
        DB::statement("ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_title_check CHECK (btrim(title) <> '' AND char_length(title) <= 200)");
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_body_length_check CHECK (char_length(body_md) <= 100000)');
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_version_no_check CHECK (version_no >= 1)');
        DB::statement("ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_folder_check CHECK (folder_id IS NULL OR kind = 'general')");
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_client_fk FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_project_client_fk FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_project_check CHECK (project_id IS NULL OR client_id IS NOT NULL)');
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_client_visible_client_check CHECK (NOT client_visible OR client_id IS NOT NULL)');
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_client_visible_sensitive_check CHECK (NOT (client_visible AND sensitive))');
        DB::statement("ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_client_visible_kind_check CHECK (NOT client_visible OR kind IN ('general', 'incident'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_knowledge_article_kind() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.kind IS DISTINCT FROM OLD.kind THEN
                RAISE EXCEPTION 'the kind of a knowledge article never changes' USING ERRCODE = 'KP001';
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER knowledge_articles_kind_frozen BEFORE UPDATE OF kind ON knowledge_articles FOR EACH ROW EXECUTE FUNCTION kokpit_guard_knowledge_article_kind()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS knowledge_articles_kind_frozen ON knowledge_articles');

        Schema::dropIfExists('knowledge_articles');

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_knowledge_article_kind()');
    }
};
