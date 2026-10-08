<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Admin-only billing overrides of a task (D-12, D-13, TA-06): billing type,
 * hourly rate, fixed price, estimate and the internal billing note. A Partner
 * reads the tasks table, so none of this may live there; this table is closed to
 * Partners by the model (DeniesPartners) and its policy.
 *
 * A task has a row only while it overrides something (D-14): a task without a
 * row inherits everything from its project and client, and nothing is copied.
 * One row per task (unique task_id). The row has its own uuid primary key
 * because schema rule R6 demands a uuidv7() default on any single-column uuid
 * primary key. Money follows the column pair convention (minor + currency, both
 * null or both set). The estimate is stored in whole seconds.
 *
 * Non-billable exists only here: the billing type of a task has four values
 * (inherit, hourly, fixed_price, non_billable), the project type has two.
 *
 * The currency of the money equals the client's currency; that cross-table rule
 * is enforced by the domain Action and the client currency lock, not by a trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_billing', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('task_id')->constrained('tasks')->restrictOnDelete();

            $table->string('billing_type', 16)->default('inherit');

            $table->bigInteger('hourly_rate_minor')->nullable();
            $table->char('hourly_rate_currency', 3)->nullable();
            $table->bigInteger('fixed_price_minor')->nullable();
            $table->char('fixed_price_currency', 3)->nullable();

            $table->integer('estimate_seconds')->nullable();
            $table->text('internal_note')->nullable();

            $table->timestampsTz();

            $table->unique('task_id', 'task_billing_task_id_unique');
        });

        DB::statement("ALTER TABLE task_billing ADD CONSTRAINT task_billing_billing_type_check CHECK (billing_type IN ('inherit', 'hourly', 'fixed_price', 'non_billable'))");

        foreach (['hourly_rate', 'fixed_price'] as $name) {
            DB::statement("ALTER TABLE task_billing ADD CONSTRAINT task_billing_{$name}_pair_check CHECK (({$name}_minor IS NULL) = ({$name}_currency IS NULL))");
            DB::statement("ALTER TABLE task_billing ADD CONSTRAINT task_billing_{$name}_minor_check CHECK ({$name}_minor >= 0)");
            DB::statement("ALTER TABLE task_billing ADD CONSTRAINT task_billing_{$name}_currency_check CHECK ({$name}_currency ~ '^[A-Z]{3}\$')");
        }

        DB::statement('ALTER TABLE task_billing ADD CONSTRAINT task_billing_currencies_match_check CHECK (hourly_rate_currency IS NULL OR fixed_price_currency IS NULL OR hourly_rate_currency = fixed_price_currency)');
        DB::statement('ALTER TABLE task_billing ADD CONSTRAINT task_billing_estimate_seconds_check CHECK (estimate_seconds >= 0)');
        DB::statement("ALTER TABLE task_billing ADD CONSTRAINT task_billing_fixed_price_required_check CHECK (billing_type <> 'fixed_price' OR fixed_price_minor IS NOT NULL)");
    }
};
