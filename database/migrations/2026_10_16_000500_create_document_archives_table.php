<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The record behind every ZIP download of documents (DO-02, D-08).
 *
 * One row per archive request. The row belongs to the Admin who asked for it (owner scope in the model): even a
 * second Admin reads nothing of it, and a Partner reads nothing at all. The finished ZIP lives on the default
 * (private) disk and is downloaded through the application with a permission check, never through a public URL.
 *
 * The path is server-generated: `document-archives/<archive uuid>.zip`. The database pins that exact shape, so
 * no user text (a file name, a client name, a traversal segment) can ever reach a storage path, whatever code
 * writes the row. The download name is built from the creation day only (see the model).
 *
 * document_ids is the JSON array of the requested document ids, document_count their number (always the length of
 * that array, at least one) and total_bytes the summed size of the source files. summary is the server-built text
 * the list shows. The request and the build job come with plan 09-17, the list, the download route and the prune
 * with plan 09-18.
 *
 * Expiry: expires_at is set at creation (creation plus the retention) and reset to completion plus the retention
 * when the archive is ready, so a finished archive is kept for the full retention from completion, and a pending
 * or failed record expires the same number of days after creation. The scheduled prune deletes every expired row
 * and its file.
 *
 * user_id has the default NO ACTION on delete: users are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_archives', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('user_id')->constrained('users');
            $table->string('status', 16)->default('pending');
            $table->string('summary', 255);
            $table->jsonb('document_ids');
            $table->integer('document_count');
            $table->bigInteger('total_bytes');
            $table->string('path', 255)->nullable();
            // Whole seconds, like every instant of the time area.
            $table->timestampTz('completed_at', 0)->nullable();
            $table->timestampTz('expires_at', 0)->index('document_archives_expires_at_index');

            $table->timestampsTz();

            $table->index(['user_id', 'created_at'], 'document_archives_user_created_index');
        });

        DB::statement("ALTER TABLE document_archives ADD CONSTRAINT document_archives_status_check CHECK (status IN ('pending', 'ready', 'failed'))");
        DB::statement("ALTER TABLE document_archives ADD CONSTRAINT document_archives_ready_check CHECK (status <> 'ready' OR (path IS NOT NULL AND completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE document_archives ADD CONSTRAINT document_archives_path_check CHECK (path IS NULL OR path ~ '^document-archives/[0-9a-f-]{36}\\.zip\$')");
        DB::statement("ALTER TABLE document_archives ADD CONSTRAINT document_archives_ids_check CHECK (jsonb_typeof(document_ids) = 'array')");
        // The CASE keeps the length call away from a non-array value, so the ids check above is the one that refuses it.
        DB::statement("ALTER TABLE document_archives ADD CONSTRAINT document_archives_count_check CHECK (document_count > 0 AND document_count = CASE WHEN jsonb_typeof(document_ids) = 'array' THEN jsonb_array_length(document_ids) END)");
        DB::statement('ALTER TABLE document_archives ADD CONSTRAINT document_archives_bytes_check CHECK (total_bytes >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_archives');
    }
};
