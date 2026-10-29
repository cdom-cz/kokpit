<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Admin can hide a task from the kanban boards (D-04, D-05).
 *
 * `hidden_on_board` is a display preference: a hidden task disappears from the global and the project board only, and
 * stays in the lists, the search, its task page, its time entries and its timer. The column is NOT NULL DEFAULT false,
 * so every existing row keeps showing on the board and no backfill is needed. There is no index: the board filters a
 * few hundred rows inside an already narrowed query.
 *
 * The flag carries no money and nothing internal, so every Partner of the client may read it. That is why it lives on
 * `tasks` and why the pinned Partner-safe column list of the tasks table grows by it. Only the Admin can write it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('hidden_on_board')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('hidden_on_board');
        });
    }
};
