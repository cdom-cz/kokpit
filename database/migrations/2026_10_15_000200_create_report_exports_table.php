<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The record behind every queued report export (RE-03, D-09).
 *
 * One row per export request. The row belongs to the Admin who made it (owner scope in the model): even a second
 * Admin reads nothing of it, and a Partner reads nothing at all. The finished file lives on the default (private)
 * disk and is downloaded through the application with a permission check, never through a public URL.
 *
 * The path is server-generated: `report-exports/<export uuid>.<csv|xlsx>`. The database pins that exact shape,
 * so no user text (a client name, a project name, a traversal segment) can ever reach a storage path, whatever
 * code writes the row. The download name is built from the stored period only (see the model).
 *
 * Expiry: expires_at is set at creation (creation plus the retention) and reset to completion plus the retention
 * when the export is ready, so a finished export is kept for the full retention from completion, and a pending
 * or failed record expires the same number of days after creation. The scheduled prune of plan 08-12 deletes
 * every expired row and its file, so stale pending and failed records are removed too.
 *
 * summary is the server-built "Zadání" text; filters holds validated ids and Prague days only (a JSON object).
 * user_id has the default NO ACTION on delete: users are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('user_id')->constrained('users');
            $table->string('format', 8);
            $table->string('status', 16)->default('pending');
            $table->string('summary', 255);
            $table->jsonb('filters');
            $table->string('path', 255)->nullable();
            $table->integer('row_count')->nullable();
            // Whole seconds, like every instant of the time area.
            $table->timestampTz('completed_at', 0)->nullable();
            $table->timestampTz('expires_at', 0)->index('report_exports_expires_at_index');

            $table->timestampsTz();

            $table->index(['user_id', 'created_at'], 'report_exports_user_created_index');
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('csv', 'xlsx'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('pending', 'ready', 'failed'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_filters_object_check CHECK (jsonb_typeof(filters) = 'object')");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_ready_check CHECK (status <> 'ready' OR (path IS NOT NULL AND completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_path_check CHECK (path IS NULL OR path ~ '^report-exports/[0-9a-f-]{36}\\.(csv|xlsx)\$')");
        DB::statement('ALTER TABLE report_exports ADD CONSTRAINT report_exports_row_count_check CHECK (row_count IS NULL OR row_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
