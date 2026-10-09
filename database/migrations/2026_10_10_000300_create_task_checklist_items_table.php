<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The private todo checklist of a task or a subtask (TA-03).
 *
 * The tasks table is readable by a Partner, so the working notes of the Admin
 * live in their own table that the model closes to Partners (DeniesPartners and
 * the admin-only policy). No column of `tasks` changes.
 *
 * The items of a task are ordered by `position`; positions need no uniqueness,
 * because the edit form rewrites the whole list on save and ties are broken by
 * the id. The text is non-blank and at most 500 characters, the position is not
 * negative, and an item always belongs to an existing task (RESTRICT: a task is
 * archived, never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();

            $table->string('text', 500);
            $table->boolean('is_done')->default(false);
            $table->integer('position')->default(0);

            $table->timestampsTz();

            $table->index(['task_id', 'position'], 'task_checklist_items_task_id_position_index');
        });

        DB::statement('ALTER TABLE task_checklist_items ADD CONSTRAINT task_checklist_items_text_check CHECK (length(btrim(text, E\' \\t\\r\\n\')) > 0)');
        DB::statement('ALTER TABLE task_checklist_items ADD CONSTRAINT task_checklist_items_position_check CHECK (position >= 0)');
    }
};
