<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A planner task may link to a Kokpit task (P17-07, D-01).
 *
 * This is the first link from the daily planner to a Kokpit task. The title of the planner task stays a snapshot taken
 * when the task was picked; the link only remembers which task it was. Kokpit tasks are only archived (soft-deleted) by
 * the application, which keeps the link. A hard delete of a task clears the link with SET NULL and the snapshot title
 * stays, so the planner row never disappears with the task.
 *
 * The planner tables stay Admin-only and owner-scoped; the column is written only by AddSignalTask. No backfill: the
 * existing planner rows are free text and stay unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_tasks', function (Blueprint $table): void {
            $table->foreignUuid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->index('task_id', 'signal_tasks_task_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('signal_tasks', function (Blueprint $table): void {
            $table->dropIndex('signal_tasks_task_id_index');
            $table->dropConstrainedForeignId('task_id');
        });
    }
};
