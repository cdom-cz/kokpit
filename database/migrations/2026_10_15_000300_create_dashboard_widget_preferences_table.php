<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Whether a user keeps a dashboard card collapsed (quick task 261015-cv5).
 *
 * One row per user and card. A user without a row sees the card expanded, so no backfill is needed. The state is
 * stored on the server, not in the browser, so it follows the user across devices and is applied in the first
 * render. Only SetDashboardWidgetCollapsed writes the table, for the actor's own rows, and only
 * CollapsedWidgets reads it.
 *
 * - the FK to users cascades on delete: a layout preference has no value without its user and must never block
 *   removing one;
 * - UNIQUE (user_id, widget_key) allows one row per card and makes the single-statement upsert safe when two tabs
 *   toggle at once; it also serves as the index of the FK;
 * - the CHECK closes widget_key to the six cards that exist. A new dashboard widget needs a new DashboardWidget
 *   case and a migration that widens dashboard_widget_preferences_widget_key_check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widget_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('widget_key', 64);
            $table->boolean('collapsed');
            $table->timestampsTz();

            $table->unique(['user_id', 'widget_key'], 'dashboard_widget_preferences_user_widget_unique');
        });

        DB::statement("ALTER TABLE dashboard_widget_preferences ADD CONSTRAINT dashboard_widget_preferences_widget_key_check CHECK (widget_key IN ('hours_stats', 'today_plan', 'unbilled_per_client', 'upcoming_deadlines', 'partner_tasks', 'partner_projects'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widget_preferences');
    }
};
