<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The "Narozeniny dnes" card of the Admin dashboard (birthdays of client contacts) is collapsible like the other cards,
 * so dashboard_widget_preferences_widget_key_check must accept its key `birthdays_today` next to the eight that exist.
 *
 * The CHECK is dropped and added again in one transaction: the table is never without it. down() deletes the rows of
 * the new card first, because the old list would refuse them, and restores the eight keys.
 */
return new class extends Migration
{
    private const string CONSTRAINT = 'dashboard_widget_preferences_widget_key_check';

    public function up(): void
    {
        DB::statement('ALTER TABLE dashboard_widget_preferences DROP CONSTRAINT '.self::CONSTRAINT);
        DB::statement('ALTER TABLE dashboard_widget_preferences ADD CONSTRAINT '.self::CONSTRAINT." CHECK (widget_key IN ('hours_stats', 'today_plan', 'birthdays_today', 'unpaid_invoices', 'unresolved_payments', 'unbilled_per_client', 'upcoming_deadlines', 'partner_tasks', 'partner_projects'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM dashboard_widget_preferences WHERE widget_key = 'birthdays_today'");
        DB::statement('ALTER TABLE dashboard_widget_preferences DROP CONSTRAINT '.self::CONSTRAINT);
        DB::statement('ALTER TABLE dashboard_widget_preferences ADD CONSTRAINT '.self::CONSTRAINT." CHECK (widget_key IN ('hours_stats', 'today_plan', 'unpaid_invoices', 'unresolved_payments', 'unbilled_per_client', 'upcoming_deadlines', 'partner_tasks', 'partner_projects'))");
    }
};
