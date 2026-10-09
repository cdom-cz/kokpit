<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether the user keeps the side panel "Poslední záznamy" open at docked width (D-08, U-5).
 *
 * Nullable on purpose: null means the user never chose and the panel follows the default (open
 * from 80rem up), so existing rows need no backfill. The choice is stored on the server, not in
 * the browser, so it follows the user across devices and is applied in the first render. Only
 * SetTimePanelOpen writes it, for the actor's own row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('time_panel_open')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('time_panel_open');
        });
    }
};
