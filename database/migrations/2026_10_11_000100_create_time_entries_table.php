<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The tracked time: one table for running and finished time entries.
 *
 * Admin-only. Measured time reaches no Partner, so the model denies Partners
 * and the table is never Partner-readable.
 *
 * `ended_at` NULL means the entry is running. The duration is the stored
 * generated column `duration_seconds`, computed from the two timestamptz(0)
 * instants. timestamptz(0) ROUNDS a fractional second, so every writer
 * truncates through TimerClock first and a start is never stored a second late.
 *
 * The database is the guarantee:
 * - at most one running entry per user: the partial unique index
 *   time_entries_one_running_per_user;
 * - client, project and task cannot disagree: the composite foreign keys
 *   (project_id, client_id) and (task_id, project_id) run over the composite
 *   unique keys added below (the existing tasks_ident_unique is
 *   (id, project_id, depth) and does not fit). A NULL column skips a composite
 *   key (MATCH SIMPLE), so a task without a project is caught by its CHECK;
 * - the end is never before the start (an equal end is a legitimate zero-length
 *   entry: a start in the same second stops the running entry at the same
 *   instant);
 * - a billed entry is finished and billable, and carries its billing time.
 *
 * The billed entry is frozen by the generic guard trigger. The mutable list
 * MUST keep `duration_seconds`: PostgreSQL computes a generated column after
 * the BEFORE trigger ran, so NEW holds NULL there while OLD holds the value,
 * and the guard would refuse every update of a billed row, the unlock included.
 * Editing the start or the end of a billed row stays refused. The Phase 10
 * migration that adds snapshot columns must re-create this guard with an
 * extended mutable list.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Targets of the composite foreign keys of time_entries; additive, no column changes.
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_id_client_unique UNIQUE (id, client_id)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_id_project_unique UNIQUE (id, project_id)');

        Schema::create('time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            // Both links are composite foreign keys added below.
            $table->uuid('project_id')->nullable();
            $table->uuid('task_id')->nullable();

            $table->string('description', 1000)->nullable();

            // Whole seconds. NULL ended_at = running.
            $table->timestampTz('started_at', 0);
            $table->timestampTz('ended_at', 0)->nullable();
            $table->integer('duration_seconds')->nullable()
                ->storedAs('CASE WHEN ended_at IS NULL THEN NULL ELSE (EXTRACT(EPOCH FROM (ended_at - started_at)))::integer END');

            $table->boolean('billable')->default(true);
            $table->string('billing_state', 16)->default('unbilled');
            $table->timestampTz('billed_at', 0)->nullable();

            // Internal marker of the forgotten-timer notice.
            $table->timestampTz('long_running_notified_at', 0)->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'started_at'], 'time_entries_user_started_index');
            $table->index(['project_id', 'started_at'], 'time_entries_project_started_index');
            $table->index(['client_id', 'started_at'], 'time_entries_client_started_index');
        });

        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_project_client_fk FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_task_project_fk FOREIGN KEY (task_id, project_id) REFERENCES tasks (id, project_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_task_needs_project_check CHECK (task_id IS NULL OR project_id IS NOT NULL)');
        DB::statement('ALTER TABLE time_entries ADD CONSTRAINT time_entries_end_check CHECK (ended_at IS NULL OR ended_at >= started_at)');
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_state_check CHECK (billing_state IN ('unbilled', 'billed'))");
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_billed_at_check CHECK ((billing_state = 'billed') = (billed_at IS NOT NULL))");
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_billed_finished_check CHECK (billing_state = 'unbilled' OR (ended_at IS NOT NULL AND billable))");

        DB::statement('CREATE UNIQUE INDEX time_entries_one_running_per_user ON time_entries (user_id) WHERE ended_at IS NULL');
        DB::statement('CREATE INDEX time_entries_task_id_index ON time_entries (task_id) WHERE task_id IS NOT NULL');
        DB::statement("CREATE INDEX time_entries_unbilled_index ON time_entries (project_id, started_at) WHERE billing_state = 'unbilled' AND billable AND ended_at IS NOT NULL");

        DB::unprepared(Immutability::guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state', 'billed_at', 'duration_seconds']));
        DB::unprepared(Immutability::truncateGuardSql('time_entries'));
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_id_project_unique');
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_id_client_unique');
    }
};
