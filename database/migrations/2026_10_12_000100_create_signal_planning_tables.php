<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The daily planner "Signal": settings, recurring templates, tasks, deep-work days and unlocked days.
 *
 * Every row belongs to one user (user_id, restrictOnDelete like the rest of Kokpit); the model scope
 * filters by it, and the keys below make the database refuse what the scope cannot:
 * - a task and the recurring template it came from always belong to the same user: the composite
 *   foreign key (recurring_id, user_id) runs over the composite unique key of the template, and
 *   deleting a template only nulls recurring_id (column list of ON DELETE SET NULL, PostgreSQL 15+);
 * - a template yields at most one task per day: the partial unique index on (recurring_id, for_date)
 *   is what makes the lazy materialization idempotent under parallel requests;
 * - is_done and completed_at never disagree;
 * - categories and the weekdays are closed sets (CHECK), titles are never blank;
 * - a deep-work day has 0 <= completed <= planned <= 12, one row per user and day;
 * - one settings row and one unlock row per user (and day).
 *
 * The weekdays of a template are a smallint[] of indexes 0 (Monday) .. 6 (Sunday), 1 to 7 of them, read
 * and written through WeekdaysCast; "fires on this weekday" is the array operator @>.
 *
 * A date column holds a calendar day of the Europe/Prague planner, never an instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->smallInteger('deep_work_weekday_blocks')->default(3);
            $table->smallInteger('deep_work_weekend_blocks')->default(0);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE signal_settings ADD CONSTRAINT signal_settings_blocks_check CHECK (deep_work_weekday_blocks BETWEEN 0 AND 12 AND deep_work_weekend_blocks BETWEEN 0 AND 12)');

        Schema::create('signal_recurring_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('category', 16);
            // The weekdays column (smallint[]) is added below: Blueprint has no array type.
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['user_id', 'active'], 'signal_recurring_tasks_user_active_index');
        });

        DB::statement('ALTER TABLE signal_recurring_tasks ADD CONSTRAINT signal_recurring_tasks_id_user_unique UNIQUE (id, user_id)');
        DB::statement("ALTER TABLE signal_recurring_tasks ADD CONSTRAINT signal_recurring_tasks_category_check CHECK (category IN ('main', 'medium', 'other'))");
        DB::statement('ALTER TABLE signal_recurring_tasks ADD COLUMN weekdays smallint[] NOT NULL');
        DB::statement('ALTER TABLE signal_recurring_tasks ADD CONSTRAINT signal_recurring_tasks_weekdays_check CHECK (cardinality(weekdays) BETWEEN 1 AND 7 AND weekdays <@ ARRAY[0, 1, 2, 3, 4, 5, 6]::smallint[])');
        DB::statement("ALTER TABLE signal_recurring_tasks ADD CONSTRAINT signal_recurring_tasks_title_check CHECK (btrim(title) <> '')");

        Schema::create('signal_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 255);
            $table->date('for_date');
            $table->string('category', 16);
            $table->boolean('is_done')->default(false);
            $table->timestampTz('completed_at', 0)->nullable();
            // Composite foreign key added below.
            $table->uuid('recurring_id')->nullable();
            // Manual order inside (day, category); a new task goes to the end of its group.
            $table->smallInteger('position')->default(0);
            $table->timestampsTz();

            $table->index(['user_id', 'for_date', 'category', 'position'], 'signal_tasks_day_order_index');
        });

        DB::statement("ALTER TABLE signal_tasks ADD CONSTRAINT signal_tasks_category_check CHECK (category IN ('main', 'medium', 'other', 'extra'))");
        DB::statement("ALTER TABLE signal_tasks ADD CONSTRAINT signal_tasks_title_check CHECK (btrim(title) <> '')");
        DB::statement('ALTER TABLE signal_tasks ADD CONSTRAINT signal_tasks_done_check CHECK (is_done = (completed_at IS NOT NULL))');
        DB::statement('ALTER TABLE signal_tasks ADD CONSTRAINT signal_tasks_recurring_user_fk FOREIGN KEY (recurring_id, user_id) REFERENCES signal_recurring_tasks (id, user_id) ON DELETE SET NULL (recurring_id)');
        DB::statement('CREATE UNIQUE INDEX signal_tasks_recurring_day_unique ON signal_tasks (recurring_id, for_date) WHERE recurring_id IS NOT NULL');

        Schema::create('signal_deep_work_days', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->date('for_date');
            // Frozen from the settings when the row is first written, so a later change of the
            // settings does not rewrite history.
            $table->smallInteger('planned');
            $table->smallInteger('completed')->default(0);
            $table->timestampsTz();

            $table->unique(['user_id', 'for_date'], 'signal_deep_work_days_user_day_unique');
        });

        DB::statement('ALTER TABLE signal_deep_work_days ADD CONSTRAINT signal_deep_work_days_range_check CHECK (completed >= 0 AND completed <= planned AND planned <= 12)');

        Schema::create('signal_day_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            // The presence of a row means: this past day is unlocked for full editing.
            $table->date('for_date');
            $table->timestampsTz();

            $table->unique(['user_id', 'for_date'], 'signal_day_overrides_user_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_day_overrides');
        Schema::dropIfExists('signal_deep_work_days');
        Schema::dropIfExists('signal_tasks');
        Schema::dropIfExists('signal_recurring_tasks');
        Schema::dropIfExists('signal_settings');
    }
};
