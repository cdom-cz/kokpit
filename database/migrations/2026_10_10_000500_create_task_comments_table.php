<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The comments of a task or a subtask (TA-04, D-08).
 *
 * A comment is append-only: nobody edits or deletes it, so there is no soft
 * delete. A comment is internal (the Admin's own remark, never shown to a
 * Partner) or visible to the client; an escalation comment (plan 05-13) is
 * always visible, so the two flags can never both be set. The body is HTML that
 * the Action has cleaned, and it is never blank. Both foreign keys restrict:
 * tasks are archived and users are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->restrictOnDelete();

            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_escalation')->default(false);

            $table->timestampsTz();

            $table->index(['task_id', 'created_at'], 'task_comments_task_id_created_at_index');
        });

        DB::statement('ALTER TABLE task_comments ADD CONSTRAINT task_comments_body_check CHECK (length(btrim(body, E\' \\t\\r\\n\')) > 0)');
        DB::statement('ALTER TABLE task_comments ADD CONSTRAINT task_comments_internal_escalation_check CHECK (NOT (is_internal AND is_escalation))');
    }
};
