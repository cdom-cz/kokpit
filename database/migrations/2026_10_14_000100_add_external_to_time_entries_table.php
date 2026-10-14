<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The free-form `external` object of a time entry (D-15): an import helper keeps
 * the id of the entry in its own system next to ours. It is jsonb, nullable, and
 * only ever a JSON object: the CHECK refuses `[]`, `"text"`, `5` and `true`
 * whichever writer sends them, so the API response never has to guess a type.
 *
 * No index and no unique constraint on purpose: nothing here looks an entry up
 * by `external`, and the API does not deduplicate on it. The column is not
 * logged in the activity log and not shown in the panel.
 *
 * The frozen-row guard needs no change: it compares `to_jsonb(NEW) - allowed`
 * with `to_jsonb(OLD)`, so a new column is frozen on a billed entry automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE time_entries ADD COLUMN external jsonb');
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_external_object_check CHECK (external IS NULL OR jsonb_typeof(external) = 'object')");
    }
};
