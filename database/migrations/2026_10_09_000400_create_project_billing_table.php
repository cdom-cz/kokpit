<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Admin-only billing terms of a project (D-05): billing type, hourly rate,
 * fixed price, estimate and the internal note. A Partner reads the projects
 * table, so none of this may live there; this table is closed to Partners by
 * the model (DeniesPartners) and its policy.
 *
 * One row per project (unique project_id). The row has its own uuid primary
 * key because schema rule R6 demands a uuidv7() default on any single-column
 * uuid primary key. Money follows the column pair convention (minor + currency,
 * both null or both set). The estimate is stored in whole seconds, the
 * duration unit of the project.
 *
 * The currency of the money equals the client's currency; that cross-table rule
 * is enforced by the domain Actions and the client currency lock, not by a
 * trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_billing', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();

            $table->string('billing_type', 16);

            $table->bigInteger('hourly_rate_minor')->nullable();
            $table->char('hourly_rate_currency', 3)->nullable();
            $table->bigInteger('fixed_price_minor')->nullable();
            $table->char('fixed_price_currency', 3)->nullable();

            $table->integer('estimate_seconds')->nullable();
            $table->text('internal_note')->nullable();

            $table->timestampsTz();

            $table->unique('project_id', 'project_billing_project_id_unique');
        });

        DB::statement("ALTER TABLE project_billing ADD CONSTRAINT project_billing_billing_type_check CHECK (billing_type IN ('hourly', 'fixed_price'))");

        foreach (['hourly_rate', 'fixed_price'] as $name) {
            DB::statement("ALTER TABLE project_billing ADD CONSTRAINT project_billing_{$name}_pair_check CHECK (({$name}_minor IS NULL) = ({$name}_currency IS NULL))");
            DB::statement("ALTER TABLE project_billing ADD CONSTRAINT project_billing_{$name}_minor_check CHECK ({$name}_minor >= 0)");
            DB::statement("ALTER TABLE project_billing ADD CONSTRAINT project_billing_{$name}_currency_check CHECK ({$name}_currency ~ '^[A-Z]{3}\$')");
        }

        DB::statement('ALTER TABLE project_billing ADD CONSTRAINT project_billing_currencies_match_check CHECK (hourly_rate_currency IS NULL OR fixed_price_currency IS NULL OR hourly_rate_currency = fixed_price_currency)');
        DB::statement('ALTER TABLE project_billing ADD CONSTRAINT project_billing_estimate_seconds_check CHECK (estimate_seconds >= 0)');
        DB::statement("ALTER TABLE project_billing ADD CONSTRAINT project_billing_fixed_price_required_check CHECK (billing_type <> 'fixed_price' OR fixed_price_minor IS NOT NULL)");
    }
};
