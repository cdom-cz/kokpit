<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The images of the knowledge articles and the index of which stored version references which image (Phase 20; IMG-02,
 * IMG-03; D-02, D-04, D-06, D-08, D-16, D-17; rulings R-20-01-1 to R-20-01-5 of plan 20-01).
 *
 * D-02: an upload is stored pending: `knowledge_article_images.article_id` is NULL until an article save binds it.
 * D-04: an image belongs to exactly one article. Once `article_id` is set, the row trigger refuses any change of it.
 * D-06: only PNG, JPEG, WebP and GIF, the longer side at most 2400 px. The 10 MiB cap of the upload is enforced by the
 *       pipeline; the CHECK on `size_bytes` bounds the stored, re-encoded size, which can be larger than the upload.
 * D-08: only the re-encoded output is stored; the row describes that output (mime type, size, digest).
 * D-16: `knowledge_article_version_images` is written at save time and ties one version to one image.
 * D-17: an image survives while any stored version references it: both foreign keys of the reference table are RESTRICT.
 *
 * What changes:
 * - `knowledge_article_versions` gets `UNIQUE (id, article_id)`. The id is already unique, so the pair is too; the
 *   constraint only exists to be the target of the composite foreign key below. Adding a constraint builds an index and
 *   touches no row, so the frozen trigger of the versions table is not involved.
 * - `knowledge_article_images`: one row per re-encoded image. `article_id` NULL means pending. CHECKs on the mime type,
 *   width, height, size, the lowercase sha256 hex digest (strong ETag), the length of the file name (shown only in the
 *   Obrázky table, never logged) and `orphaned_at` (only on a bound image). `UNIQUE (id, article_id)` is the target of
 *   the composite foreign key. The partial index on `created_at` where `article_id IS NULL` serves the 24-hour sweep of
 *   pending images.
 * - `knowledge_article_version_images`: a surrogate uuid key (every model is a KokpitModel with HasUuids) and
 *   `UNIQUE (version_id, image_id)`. The foreign keys are composite, `(version_id, article_id)` to the versions and
 *   `(image_id, article_id)` to the images, so a reference from a version of article B to an image of article A is
 *   impossible in the database whatever code writes it. A pending image (article_id NULL) matches no composite key
 *   either. The rows stay mutable: only the reviewed writers insert or delete them, so there is no frozen trigger.
 * - `kokpit_refuse_knowledge_image_change()` on the row trigger `knowledge_article_images_frozen` (BEFORE UPDATE): it
 *   raises KP001 unless the row differs from the old row in `article_id` and `orphaned_at` alone, compared as jsonb, so a
 *   column added in a later phase is frozen by default. The bind (NULL to an article) and the orphan stamp are the only
 *   updates; a bound `article_id` never changes. DELETE stays allowed (cleanup and delete-for-good delete rows; RESTRICT
 *   protects the referenced ones).
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and `session_replication_role =
 * replica` skips them, so the production application role must not be a superuser.
 *
 * down() refuses with an exception while an image row exists, because dropping the table would orphan the stored
 * objects. On an empty table it drops the trigger, the function, both tables and the versions constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE knowledge_article_versions ADD CONSTRAINT knowledge_article_versions_id_article_unique UNIQUE (id, article_id)');

        Schema::create('knowledge_article_images', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->uuid('article_id')->nullable();
            $table->uuid('uploaded_by');
            $table->text('file_name');
            $table->text('mime_type');
            $table->integer('width');
            $table->integer('height');
            $table->integer('size_bytes');
            $table->text('sha256');
            $table->timestampTz('orphaned_at')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index('article_id', 'knowledge_article_images_article_id_index');
            $table->index('uploaded_by', 'knowledge_article_images_uploaded_by_index');
        });

        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_article_fk FOREIGN KEY (article_id) REFERENCES knowledge_articles (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_file_name_check CHECK (char_length(file_name) BETWEEN 1 AND 255)');
        DB::statement("ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_mime_type_check CHECK (mime_type IN ('image/png', 'image/jpeg', 'image/webp', 'image/gif'))");
        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_width_check CHECK (width BETWEEN 1 AND 2400)');
        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_height_check CHECK (height BETWEEN 1 AND 2400)');
        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_size_bytes_check CHECK (size_bytes BETWEEN 1 AND 32 * 1024 * 1024)');
        DB::statement("ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_orphaned_check CHECK (orphaned_at IS NULL OR article_id IS NOT NULL)');
        DB::statement('ALTER TABLE knowledge_article_images ADD CONSTRAINT knowledge_article_images_id_article_unique UNIQUE (id, article_id)');
        DB::statement('CREATE INDEX knowledge_article_images_pending_index ON knowledge_article_images (created_at) WHERE article_id IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_refuse_knowledge_image_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF (to_jsonb(NEW) - 'article_id' - 'orphaned_at') <> (to_jsonb(OLD) - 'article_id' - 'orphaned_at')
                 OR (OLD.article_id IS NOT NULL AND NEW.article_id IS DISTINCT FROM OLD.article_id) THEN
                RAISE EXCEPTION 'a stored knowledge image is never changed' USING ERRCODE = 'KP001';
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER knowledge_article_images_frozen BEFORE UPDATE ON knowledge_article_images FOR EACH ROW EXECUTE FUNCTION kokpit_refuse_knowledge_image_change()');

        Schema::create('knowledge_article_version_images', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->uuid('version_id');
            $table->uuid('image_id');
            $table->uuid('article_id');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('image_id', 'knowledge_article_version_images_image_id_index');
        });

        DB::statement('ALTER TABLE knowledge_article_version_images ADD CONSTRAINT knowledge_article_version_images_version_image_unique UNIQUE (version_id, image_id)');
        DB::statement('ALTER TABLE knowledge_article_version_images ADD CONSTRAINT knowledge_article_version_images_version_fk FOREIGN KEY (version_id, article_id) REFERENCES knowledge_article_versions (id, article_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE knowledge_article_version_images ADD CONSTRAINT knowledge_article_version_images_image_fk FOREIGN KEY (image_id, article_id) REFERENCES knowledge_article_images (id, article_id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (DB::table('knowledge_article_images')->exists()) {
            throw new RuntimeException('Rolling back would orphan stored knowledge images.');
        }

        Schema::dropIfExists('knowledge_article_version_images');

        DB::unprepared('DROP TRIGGER IF EXISTS knowledge_article_images_frozen ON knowledge_article_images');

        Schema::dropIfExists('knowledge_article_images');

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_refuse_knowledge_image_change()');

        DB::statement('ALTER TABLE knowledge_article_versions DROP CONSTRAINT IF EXISTS knowledge_article_versions_id_article_unique');
    }
};
