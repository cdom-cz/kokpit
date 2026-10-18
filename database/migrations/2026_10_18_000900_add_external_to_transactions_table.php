<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The free-form `external` object of a transaction (quick task 261018-mic, the same object a time entry carries
 * since D-15): an import tool keeps the id of the transaction in its own system next to ours (a bank line id, a
 * source name, any structured reference). It is jsonb, nullable, and only ever a JSON object: the CHECK refuses
 * `[]`, `"text"`, `5` and `true` whichever writer sends them, so the API response never has to guess a type.
 *
 * No index and no unique constraint on purpose: nothing here looks a transaction up by `external`, and the API
 * does not deduplicate on it. The column is not logged in the activity log and not shown in the panel.
 *
 * kokpit_guard_payment_income() compares `to_jsonb(NEW) - allowed` with `to_jsonb(OLD) - allowed`, so the new
 * column is frozen on the income of an invoice payment without any change to the guard, deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transactions ADD COLUMN external jsonb');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_external_object_check CHECK (external IS NULL OR jsonb_typeof(external) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_external_object_check');
        DB::statement('ALTER TABLE transactions DROP COLUMN IF EXISTS external');
    }
};
