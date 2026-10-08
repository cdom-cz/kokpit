<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The task: one table for tasks and their one level of subtasks.
 *
 * This table is readable by a Partner (the tasks of the own client's
 * client-visible projects), so it holds Partner-safe columns only. Billing
 * terms, the private checklist and internal comments live in separate
 * Admin-only tables, so no column of this one can leak them.
 *
 * The database is the guarantee:
 * - `reference` (KEY-N) and (project_id, number) are unique, trashed rows
 *   included, so a reference stays valid after a task is deleted;
 * - a subtask must point at a root task of the same project: the composite
 *   foreign key runs over the generated `parent_depth` column, which is NULL
 *   for a root (the key is skipped) and 0 for a subtask (the parent must have
 *   depth 0). Sub-subtasks, cross-project parents and turning a parent into a
 *   subtask are all refused;
 * - the board position is unique per status column among active, non-done
 *   tasks. The exclusion constraint is deferred, so a transaction may rewrite
 *   the positions of a column one row at a time and is checked at commit.
 *   Done tasks are ordered by `completed_at` and hold no slot.
 *
 * Enum-like columns are varchar plus a CHECK constraint, as on projects; the
 * value lists equal those of the project constraints and the ProjectStatus and
 * ProjectPriority enums. The escalation columns are used by the escalation
 * action of a later plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            // The parent link is the composite foreign key added below.
            $table->uuid('parent_id')->nullable();
            $table->smallInteger('depth');
            $table->smallInteger('parent_depth')->nullable()
                ->storedAs('CASE WHEN parent_id IS NULL THEN NULL ELSE 0::smallint END');

            $table->integer('number');
            $table->string('reference', 32);

            $table->string('title', 255);
            // Sanitised HTML.
            $table->text('description')->nullable();

            $table->string('status', 24)->default('planned');
            $table->string('priority', 8)->default('normal');
            $table->integer('position');

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->foreignUuid('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('requester_id')->constrained('users')->restrictOnDelete();

            $table->timestampTz('escalated_at')->nullable();
            $table->foreignUuid('escalated_by_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->softDeletesTz();
            $table->timestampsTz();

            // Plain unique indexes: a deleted task keeps its reference reserved.
            $table->unique('reference', 'tasks_reference_unique');
            $table->unique(['project_id', 'number'], 'tasks_project_number_unique');

            $table->index(['project_id', 'status'], 'tasks_project_status_index');
            $table->index(['status', 'position'], 'tasks_status_position_index');
            $table->index('assignee_id', 'tasks_assignee_id_index');
            $table->index('requester_id', 'tasks_requester_id_index');
            $table->index('due_date', 'tasks_due_date_index');
            $table->index('parent_id', 'tasks_parent_id_index');
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('planned', 'to_clarify', 'in_progress', 'in_review', 'ready_to_release', 'done'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_depth_check CHECK (depth = CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_ident_unique UNIQUE (id, project_id, depth)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_fk FOREIGN KEY (parent_id, project_id, parent_depth) REFERENCES tasks (id, project_id, depth) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_number_check CHECK (number >= 1)');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_reference_check CHECK (reference ~ '^[A-Z]{2,6}-[0-9]+\$')");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_dates_check CHECK (start_date IS NULL OR due_date IS NULL OR due_date >= start_date)');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_completed_check CHECK ((status = 'done') = (completed_at IS NOT NULL))");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_escalation_pair_check CHECK ((escalated_at IS NULL) = (escalated_by_id IS NULL))');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_position_check CHECK (position >= 0)');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_position_exclusion EXCLUDE USING btree (status WITH =, position WITH =) WHERE (deleted_at IS NULL AND status <> 'done') DEFERRABLE INITIALLY DEFERRED");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
