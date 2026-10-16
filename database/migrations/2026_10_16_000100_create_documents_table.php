<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The record behind every file (DO-01, D-01): one `documents` row per file, owning exactly one medialibrary
 * `media` row whose object lives on the private disk. The document is the identity of the file; the media row is
 * a storage detail of it. The object name is server-generated (`<document uuid>.<extension>`), so no user text
 * ever reaches a storage path; the extension is limited to `[a-z0-9]{1,10}` here and in the Action.
 *
 * The table is readable by a Partner (the shared files of the own client's visible projects and tasks), so it
 * holds Partner-safe columns only; the Partner scope in the model decides which rows exist for them.
 *
 * Owner: an optional morph pair. The owner types are morph aliases (`client`, `project`, `task`, `task_comment`;
 * `transaction` follows in this phase, `invoice` in Phase 10) and are deliberately NOT a CHECK list: schema rule
 * R9 already checks every stored morph type against the morph map, and a later phase must not need a table change.
 *
 * Flag rule (D-03): a file is shared with the client only when it hangs on a project, a task or a task comment.
 * A client file, a transaction file and an unattached file can never be flagged visible, whatever code writes the
 * row (the COALESCE matters: an unattached file has a NULL owner type, and a CHECK passes on NULL). An internal
 * comment's file is hidden by the Action and the scope; the row cannot tell comments apart.
 *
 * The name must hold a character that is not whitespace (a tab-only name is as blank as an empty one).
 *
 * client_id and project_id are denormalized from the owner once at upload (owners never change, only a
 * transaction's client can). The composite foreign key (project_id, client_id) -> projects (id, client_id) keeps
 * the pair consistent: a project can only be named together with its own client. Its target
 * projects_id_client_unique already exists (created with the time entries table, which owns it and drops it on
 * rollback); this table and the transactions table of a later plan reuse it.
 *
 * Exactly one media row per document: a partial unique index over media (model_id) for the `document` morph alias.
 * uploaded_by_id has the default NO ACTION on delete: users are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('visible_to_client')->default(false);

            $table->string('owner_type', 32)->nullable();
            $table->uuid('owner_id')->nullable();

            $table->foreignUuid('client_id')->nullable()->constrained('clients');
            // The link to the project is the composite foreign key added below.
            $table->uuid('project_id')->nullable();
            $table->foreignUuid('uploaded_by_id')->constrained('users');

            // Server-derived; the display name above may be anything the Admin types.
            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 127);
            $table->bigInteger('size_bytes');

            $table->timestampsTz();

            $table->index(['owner_type', 'owner_id'], 'documents_owner_index');
            $table->index(['client_id', 'created_at'], 'documents_client_created_index');
            $table->index(['created_at', 'id'], 'documents_created_index');
        });

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_owner_pair_check CHECK ((owner_type IS NULL) = (owner_id IS NULL))');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_visible_owner_check CHECK (NOT visible_to_client OR COALESCE(owner_type IN ('project', 'task', 'task_comment'), false))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_project_needs_client_check CHECK (project_id IS NULL OR client_id IS NOT NULL)');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_name_check CHECK (name ~ '[^[:space:]]')");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_size_check CHECK (size_bytes >= 0)');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_extension_check CHECK (extension IS NULL OR extension ~ '^[a-z0-9]{1,10}\$')");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_project_client_fk FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id) ON DELETE NO ACTION');

        DB::statement("CREATE UNIQUE INDEX media_document_unique ON media (model_id) WHERE model_type = 'document'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS media_document_unique');
        Schema::dropIfExists('documents');
    }
};
