<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Restore marker, redaction and the redaction record of the knowledge history (Phase 19; VER-03, VER-05, ORG-03;
 * D-10, D-11, D-12, D-13; owner decision OD-1 of 2026-11-25; rulings R-19-01-1 to R-19-01-6 of plan 19-01).
 *
 * D-10: every version is kept. Nothing in this migration deletes, coalesces or prunes a version.
 * D-11: a restore writes a new version on top; `restored_from_version_no` names the version it was copied from.
 * D-12: a redaction rewrites the body of the stored version only, keeps the row and records that it happened.
 * D-13: any version can be redacted, the latest included; `knowledge_articles.redaction_no` is the stale token that
 *       lets a concurrent editor notice a redaction of the current version.
 *
 * What changes:
 * - `knowledge_article_versions.restored_from_version_no` (integer, NULL): the number of the version a restore
 *   copied. CHECK: NULL, or at least 1 and below the row's own number. No foreign key on purpose: the schema rule
 *   requires every foreign key column to be uuid, and the target exists anyway (versions are append-only and numbered
 *   gap-free from 1 under the article row lock). Existing rows keep NULL; there is no backfill.
 * - `knowledge_articles.redaction_no` (integer, NOT NULL, default 0, CHECK >= 0): written only by the redaction
 *   Action when it redacts the current version.
 * - `kokpit_refuse_knowledge_version_change()` is replaced, keeping its name, so the row trigger
 *   `knowledge_article_versions_frozen` and the truncate guard stay as they are. The new body admits exactly one
 *   thing: an UPDATE inside a transaction that set `kokpit.redacting_knowledge_version` to 'on' (set_config with
 *   is_local = true, so it dies with the transaction) whose row differs from the old row in `body_md` alone. The
 *   comparison is over the whole row as jsonb minus `body_md`, not over a column list, so a column added in a later
 *   phase can never become rewritable by being forgotten in a list. Everything else (title, number, author, time,
 *   restore marker, DELETE, TRUNCATE) is still refused with KP001. The empty string the setting keeps after its first
 *   use in a session is treated as off.
 * - `knowledge_version_redactions`: one append-only row per redaction (version, actor, scope lines or whole, and the
 *   LENGTH of the reason the Admin typed, 1 to 500). There is deliberately no column for the reason text (OD-1): a
 *   reason may hold what the Admin pasted, so it is validated, counted and discarded, never stored.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and `session_replication_role =
 * replica` skips them, so the production application role must not be a superuser.
 *
 * down() refuses with an exception while a redaction record or a restore marker exists, because both are append-only
 * history and a rollback would erase them. On an empty history it drops the redactions table, its function and the
 * two columns, and puts the Phase 18 function back verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE knowledge_article_versions ADD COLUMN restored_from_version_no integer NULL');
        DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_restored_from_check CHECK (restored_from_version_no IS NULL OR (restored_from_version_no >= 1 AND restored_from_version_no < version_no))');

        DB::statement('ALTER TABLE knowledge_articles ADD COLUMN redaction_no integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_redaction_no_check CHECK (redaction_no >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_version_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'UPDATE'
                 AND coalesce(current_setting('kokpit.redacting_knowledge_version', true), '') = 'on'
                 AND (to_jsonb(NEW) - 'body_md') = (to_jsonb(OLD) - 'body_md') THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'a knowledge article version is never changed or deleted' USING ERRCODE = 'KP001';
            END $$
            SQL);

        Schema::create('knowledge_version_redactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->uuid('version_id');
            $table->uuid('actor_id');
            $table->text('scope');
            $table->smallInteger('reason_length');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('version_id', 'knowledge_version_redactions_version_id_index');
            $table->index('actor_id', 'knowledge_version_redactions_actor_id_index');
        });

        DB::statement('ALTER TABLE knowledge_version_redactions ADD CONSTRAINT knowledge_version_redactions_version_fk FOREIGN KEY (version_id) REFERENCES knowledge_article_versions (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_version_redactions ADD CONSTRAINT knowledge_version_redactions_actor_fk FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE knowledge_version_redactions ADD CONSTRAINT knowledge_version_redactions_scope_check CHECK (scope IN ('lines', 'whole'))");
        DB::statement('ALTER TABLE knowledge_version_redactions ADD CONSTRAINT knowledge_version_redactions_reason_length_check CHECK (reason_length BETWEEN 1 AND 500)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_redaction_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'a knowledge version redaction is never changed or deleted' USING ERRCODE = 'KP001';
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER knowledge_version_redactions_frozen BEFORE UPDATE OR DELETE ON knowledge_version_redactions FOR EACH ROW EXECUTE FUNCTION kokpit_refuse_knowledge_redaction_change()');
        DB::unprepared(Immutability::truncateGuardSql('knowledge_version_redactions'));
    }

    public function down(): void
    {
        if (DB::table('knowledge_version_redactions')->exists()
            || DB::table('knowledge_article_versions')->whereNotNull('restored_from_version_no')->exists()) {
            throw new RuntimeException('Rolling back would erase redaction records or restore markers of the knowledge history.');
        }

        DB::unprepared(Immutability::dropTruncateGuardSql('knowledge_version_redactions'));
        DB::unprepared('DROP TRIGGER IF EXISTS knowledge_version_redactions_frozen ON knowledge_version_redactions');

        Schema::dropIfExists('knowledge_version_redactions');

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_refuse_knowledge_redaction_change()');

        DB::statement('ALTER TABLE knowledge_articles DROP CONSTRAINT IF EXISTS knowledge_articles_redaction_no_check');
        DB::statement('ALTER TABLE knowledge_articles DROP COLUMN IF EXISTS redaction_no');

        DB::statement('ALTER TABLE knowledge_article_versions DROP CONSTRAINT IF EXISTS knowledge_article_versions_restored_from_check');
        DB::statement('ALTER TABLE knowledge_article_versions DROP COLUMN IF EXISTS restored_from_version_no');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_version_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'a knowledge article version is never changed or deleted' USING ERRCODE = 'KP001';
            END $$
            SQL);
    }
};
