<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The project: the unit tasks, tracked time and billing hang off.
 *
 * This table is readable by a Partner (a client-visible project of their own
 * client), so it holds Partner-safe columns only. Rates, prices, estimates,
 * billing type and internal notes belong in the Admin-only project_billing
 * table (D-05, D-07); tests/Isolation/PartnerSafeColumnsTest pins the column
 * list.
 *
 * The key is unique across ALL projects including archived ones, because task
 * keys built from it (ABC-12) stay valid references after archival (D-14).
 * Enum-like columns are varchar plus a CHECK constraint, as on clients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();

            $table->string('name', 255);
            $table->string('key', 6);
            $table->text('description')->nullable();

            $table->string('status', 24)->default('planned');
            $table->string('priority', 8)->default('normal');

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->boolean('client_visible')->default(false);

            $table->softDeletesTz();
            $table->timestampsTz();

            // Plain unique index: an archived project keeps its key reserved.
            $table->unique('key', 'projects_key_unique');
            // Serves the Partner scope (own client AND client-visible).
            $table->index(['client_id', 'client_visible'], 'projects_client_visible_index');
        });

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_key_check CHECK (key ~ '^[A-Z]{2,6}\$')");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('planned', 'to_clarify', 'in_progress', 'in_review', 'ready_to_release', 'done'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_dates_check CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date)');
    }
};
