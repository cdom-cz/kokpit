<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The weekly part of the planner "Signal": the goals of a week and the Friday recap.
 *
 * - a week is identified by its Monday: CHECK on ISODOW rejects any other date;
 * - a week holds at most three goals at the positions 1..3: the unique key over
 *   (user_id, week_start, position) is DEFERRABLE INITIALLY DEFERRED, so a reorder can rewrite
 *   the positions row by row inside one transaction and is checked once, at commit; together
 *   with the position CHECK it is also what stops a fourth goal under parallel requests;
 * - one recap per user and week;
 * - a title is never blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_weekly_goals', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->date('week_start');
            $table->string('title', 255);
            $table->smallInteger('position');
            $table->boolean('is_done')->default(false);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE signal_weekly_goals ADD CONSTRAINT signal_weekly_goals_position_check CHECK (position BETWEEN 1 AND 3)');
        DB::statement('ALTER TABLE signal_weekly_goals ADD CONSTRAINT signal_weekly_goals_monday_check CHECK (EXTRACT(ISODOW FROM week_start) = 1)');
        DB::statement("ALTER TABLE signal_weekly_goals ADD CONSTRAINT signal_weekly_goals_title_check CHECK (btrim(title) <> '')");
        DB::statement('ALTER TABLE signal_weekly_goals ADD CONSTRAINT signal_weekly_goals_position_unique UNIQUE (user_id, week_start, position) DEFERRABLE INITIALLY DEFERRED');

        Schema::create('signal_weekly_recaps', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->date('week_start');
            $table->text('what_went_well')->default('');
            $table->text('what_to_change')->default('');
            $table->timestampsTz();

            $table->unique(['user_id', 'week_start'], 'signal_weekly_recaps_user_week_unique');
        });

        DB::statement('ALTER TABLE signal_weekly_recaps ADD CONSTRAINT signal_weekly_recaps_monday_check CHECK (EXTRACT(ISODOW FROM week_start) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_weekly_recaps');
        Schema::dropIfExists('signal_weekly_goals');
    }
};
